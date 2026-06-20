<?php

namespace App\Services\Production;

use App\Models\FactoryLocation;
use App\Models\ProductionOrderItem;
use App\Models\ProductionSchedule;
use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SchedulingService
{
    public function __construct(private SpkService $spkService) {}

    private const LOCK_KEY = 'scheduling:global';
    private const LOCK_TTL_SECONDS = 120;
    private const LOCK_WAIT_SECONDS = 10;

    /**
     * Run $callback under a global scheduling lock so concurrent calls to
     * scheduleUnassignedItems / confirmMaterialArrival(Batch) / extendMaterialEta
     * cannot interleave and produce duplicate schedule rows.
     */
    private function withSchedulingLock(\Closure $callback)
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        try {
            if (!$lock->block(self::LOCK_WAIT_SECONDS)) {
                throw new \DomainException(
                    'Penjadwalan sedang berjalan. Silakan coba lagi dalam beberapa detik.'
                );
            }
            return $callback();
        } catch (LockTimeoutException $e) {
            throw new \DomainException(
                'Penjadwalan sedang berjalan. Silakan coba lagi dalam beberapa detik.'
            );
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Schedule all unassigned items for orders that are in await_material status.
     * Groups items by their assigned factory and schedules each factory independently.
     *
     * FIX #3: Removed hardcoded factory ID 1. Items are now grouped by their
     * order's assigned factory, falling back to factory 1 if unset.
     */
    public function scheduleUnassignedItems(): void
    {
        $this->withSchedulingLock(function (): void {
            $items = ProductionOrderItem::with(['productionOrder.spk.assignedFactory'])
                ->whereHas('productionOrder', function ($q) {
                    $q->whereIn('status_id', ['await_material'])
                      ->whereNotNull('production_deadline');
                })
                ->whereDoesntHave('schedules')
                ->get();

            if ($items->isEmpty()) {
                return;
            }

            $grouped = $items->groupBy(fn($item) =>
                $item->productionOrder->spk->assigned_factory ?? 1
            );

            foreach ($grouped as $factoryId => $factoryItems) {
                $this->scheduleForFactory((int) $factoryId, $factoryItems);
            }
        });
    }

    private function scheduleForFactory(int $factoryId, $items): void
    {
        $this->validateFactoryReadiness($factoryId);
        $bestSequence = $this->determineSequenceForFactory($factoryId, $items);
        $this->dispatchSequence($bestSequence, $factoryId);
    }

    /**
     * Guard dispatchSequence against incomplete factory master-data.
     *
     * Without all three stations (kayu/cat/acc) AND ≥1 team per station,
     * dispatchSequence either dereferences a null Station or tries to
     * create a schedule with a null team_id, violating the FK constraint
     * mid-transaction. Fail fast with an actionable message so the user
     * knows which factory needs to be fixed in master data.
     */
    private function validateFactoryReadiness(int $factoryId): void
    {
        $required = ['kayu', 'cat', 'acc'];
        $stations = Station::where('factory_location_id', $factoryId)
            ->whereIn('nama_station', $required)
            ->withCount('teams')
            ->get()
            ->keyBy('nama_station');

        $missingStations = [];
        $stationsWithoutTeams = [];

        foreach ($required as $name) {
            $station = $stations->get($name);
            if (!$station) {
                $missingStations[] = $name;
            } elseif ((int) $station->teams_count === 0) {
                $stationsWithoutTeams[] = $name;
            }
        }

        if (empty($missingStations) && empty($stationsWithoutTeams)) {
            return;
        }

        $parts = [];
        if (!empty($missingStations)) {
            $parts[] = 'station hilang: ' . implode(', ', $missingStations);
        }
        if (!empty($stationsWithoutTeams)) {
            $parts[] = 'station tanpa tim: ' . implode(', ', $stationsWithoutTeams);
        }

        throw new \DomainException(
            "Pabrik ID {$factoryId} belum siap untuk penjadwalan (" . implode('; ', $parts) . '). '
            . 'Lengkapi data master station dan tim sebelum menjalankan penjadwalan.'
        );
    }

    private function determineSequenceForFactory(int $factoryId, $items): array
    {
        // Pre-compute per-order item counts to avoid N+1 queries in sort
        $orderItemCounts = $items
            ->groupBy('production_order_id')
            ->map(fn($group) => $group->count());

        // Compute and persist per-item backward-scheduled deadlines before sorting
        foreach ($items as $item) {
            if ($item->production_deadline !== null) {
                continue;
            }
            $order = $item->productionOrder;
            if (!$order->production_deadline) {
                continue;
            }
            $orderItemCount = $orderItemCounts[$item->production_order_id] ?? 1;
            $minutes = $this->calculateJobMinutes($item, $orderItemCount);
            $productionDeadline = Carbon::parse($order->production_deadline)
                ->subMinutes($minutes['t_total'])
                ->subDays(config('production.buffer_days', 2));
            $item->update(['production_deadline' => $productionDeadline]);
            $item->setAttribute('production_deadline', $productionDeadline);
        }

        $sortedItems = $items->sortBy(function ($item) use ($orderItemCounts) {
            $order = $item->productionOrder;

            if ($order->is_urgent) {
                return -999999;
            }

            $rawDeadline = $item->production_deadline ?? $order->production_deadline;
            $deadline    = $rawDeadline
                ? Carbon::parse($rawDeadline)
                : Carbon::now()->addYears(10);
            $daysUntil   = Carbon::now()->diffInDays($deadline, false);

            $orderItemCount = $orderItemCounts[$item->production_order_id] ?? 1;
            $minutes        = $this->calculateJobMinutes($item, $orderItemCount);
            $hours          = $minutes['t_total'] / 60;

            return $daysUntil - $hours;
        })->values();

        $jobs = [];
        foreach ($sortedItems as $item) {
            $orderItemCount = $orderItemCounts[$item->production_order_id] ?? 1;
            $minutes        = $this->calculateJobMinutes($item, $orderItemCount);

            $jobs[] = array_merge([
                'item' => $item,
            ], $minutes);
        }

        // Five-way partition: urgent | on_going-critical | on_going-normal
        //                      | await-critical | await-normal
        //
        // Both on_going and await groups use the SAME method — critical items
        // (deadline within the window) sort by pure EDD, normal items go through
        // NEH — so a normal item keeps its NEH treatment whether its order is
        // await_material or on_going, and the sequence stays consistent across the
        // material-arrival transition.
        //
        // on_going items still dispatch before await_material ones regardless of
        // deadline. The greedy cat/acc availability tracker ($catAvail/$accAvail)
        // advances whenever a job is processed; if an await_material job is processed
        // first its slot lands at day 14+, parking a team into the future. With more
        // await jobs than teams ahead of an on_going job that would push the on_going
        // job's slot out too. Processing on_going first secures the earliest slots.
        $criticalDays = config('production.critical_window_days', 7);

        $getItemDeadline = function ($j) {
            $raw = $j['item']->production_deadline ?? $j['item']->productionOrder->production_deadline;
            return $raw ? Carbon::parse($raw) : null;
        };

        $isOnGoing = fn($j) => $j['item']->productionOrder->status_id === 'on_going';

        // Critical = has a deadline within the window; normal = no deadline or beyond it.
        $isCritical = function ($j) use ($criticalDays, $getItemDeadline) {
            $deadline = $getItemDeadline($j);
            return $deadline !== null
                && Carbon::now()->diffInDays($deadline, false) <= $criticalDays;
        };

        // Pure EDD on item deadline (fallback to order deadline).
        $eddCmp = function ($a, $b) use ($getItemDeadline) {
            $da = $getItemDeadline($a);
            $db = $getItemDeadline($b);
            return ($da ? $da->timestamp : PHP_INT_MAX) <=> ($db ? $db->timestamp : PHP_INT_MAX);
        };

        // NEH for a normal group. The pre-sort SEED controls which job NEH anchors
        // on and the insertion order, which shapes the result:
        //   'edd' (default) — earliest-deadline-first: NEH starts from deadline
        //                     order and only deviates for makespan gains, so
        //                     tighter-deadline orders keep priority (fewer misses).
        //   'lpt'           — longest-processing-first: classic NEH makespan seed,
        //                     but can demote a tight small order behind a big loose one.
        $nehSeed = config('production.neh_seed', 'edd');
        $runNehGroup = function (array $group) use ($factoryId, $nehSeed, $eddCmp) {
            if ($nehSeed === 'lpt') {
                usort($group, fn($a, $b) => $b['t_total'] <=> $a['t_total']);
            } else {
                usort($group, $eddCmp);
            }
            return count($group) > 1 ? $this->runNeh($group, $factoryId) : $group;
        };

        $urgentJobs = array_values(array_filter(
            $jobs,
            fn($j) => $j['item']->productionOrder->is_urgent
        ));

        $onGoingCriticalJobs = array_values(array_filter(
            $jobs,
            fn($j) => !$j['item']->productionOrder->is_urgent && $isOnGoing($j) && $isCritical($j)
        ));

        $onGoingNormalJobs = array_values(array_filter(
            $jobs,
            fn($j) => !$j['item']->productionOrder->is_urgent && $isOnGoing($j) && !$isCritical($j)
        ));

        $awaitCriticalJobs = array_values(array_filter(
            $jobs,
            fn($j) => !$j['item']->productionOrder->is_urgent && !$isOnGoing($j) && $isCritical($j)
        ));

        $awaitNormalJobs = array_values(array_filter(
            $jobs,
            fn($j) => !$j['item']->productionOrder->is_urgent && !$isOnGoing($j) && !$isCritical($j)
        ));

        // EDD for urgent + both critical groups.
        usort($urgentJobs, $eddCmp);
        usort($onGoingCriticalJobs, $eddCmp);
        usort($awaitCriticalJobs, $eddCmp);

        // NEH for both normal groups (run separately — they sit in different
        // start-time horizons: on_going starts now, await starts ~14 days out).
        $onGoingNormalSeq = $runNehGroup($onGoingNormalJobs);
        $awaitNormalSeq   = $runNehGroup($awaitNormalJobs);

        return array_merge(
            $urgentJobs,
            $onGoingCriticalJobs,
            $onGoingNormalSeq,
            $awaitCriticalJobs,
            $awaitNormalSeq
        );
    }

    public function getSimulatedOrderSequence($orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        $orderIds = $orders->pluck('id')->toArray();
        $items = ProductionOrderItem::with(['productionOrder.spk.assignedFactory'])
            ->whereIn('production_order_id', $orderIds)
            ->get();

        if ($items->isEmpty()) {
            return $orderIds;
        }

        $grouped = $items->groupBy(fn($item) =>
            $item->productionOrder->spk->assigned_factory ?? 1
        );

        $finalOrderSequence = [];

        foreach ($grouped as $factoryId => $factoryItems) {
            $factorySequence = $this->determineSequenceForFactory((int) $factoryId, $factoryItems);
            foreach ($factorySequence as $job) {
                $oId = $job['item']->production_order_id;
                if (!in_array($oId, $finalOrderSequence)) {
                    $finalOrderSequence[] = $oId;
                }
            }
        }

        foreach ($orderIds as $oId) {
            if (!in_array($oId, $finalOrderSequence)) {
                $finalOrderSequence[] = $oId;
            }
        }

        return $finalOrderSequence;
    }

    /**
     * FIX #6: Extracted duplicated time calculation into a single reusable method.
     * Previously this logic was written twice (once in sortBy, once in the jobs builder).
     */
    /**
     * FIX #6: Extracted duplicated time calculation into a single reusable method.
     * Previously this logic was written twice (once in sortBy, once in the jobs builder).
     */
    private function calculateJobMinutes(ProductionOrderItem $item, int $orderItemCount): array
    {
        $cm2PerM2 = (float) (config('production.cm2_per_m2') ?? 10000);
        if ($cm2PerM2 <= 0) {
            throw new \DomainException(
                "Konfigurasi 'production.cm2_per_m2' tidak valid (harus > 0). Periksa config/production.php."
            );
        }

        $baseMins = config('production.base_production_minutes') / max(1, $orderItemCount);
        $area     = ((float) $item->panjang * (float) $item->tinggi) / $cm2PerM2;
        $varMins  = $area * (int) $item->quantity * config('production.minutes_per_m2');
        $total    = $baseMins + $varMins;
        
        // Provide a default fallback array if the config key doesn't exist or returns null
        $split    = config('production.station_split') ?? [
            'kayu' => 0.0,
            'cat'  => 0.0,
            'acc'  => 0.0,
        ];

        return [
            'p_kayu'  => $total * ($split['kayu'] ?? 0.0),
            'p_cat'   => $total * ($split['cat'] ?? 0.0),
            'p_acc'   => $total * ($split['acc'] ?? 0.0),
            't_total' => $total,
        ];
    }

    private function runNeh(array $jobs, int $factoryId): array
    {
        if (count($jobs) <= 1) {
            return $jobs;
        }

        $teamsData       = $this->getTeamsDataForFactory($factoryId);
        $currentSequence = [$jobs[0]];

        for ($i = 1; $i < count($jobs); $i++) {
            $jobToInsert = $jobs[$i];
            $bestMakespan = PHP_INT_MAX;
            $bestSeq      = [];

            for ($pos = 0; $pos <= count($currentSequence); $pos++) {
                $testSeq = $currentSequence;
                array_splice($testSeq, $pos, 0, [$jobToInsert]);
                $makespan = $this->simulateMakespan($testSeq, $teamsData);

                if ($makespan < $bestMakespan) {
                    $bestMakespan = $makespan;
                    $bestSeq      = $testSeq;
                }
            }

            $currentSequence = $bestSeq;
        }

        return $currentSequence;
    }

    /**
     * Simulate the makespan for a given sequence with optional tardiness penalty (R4).
     *
     * When tardiness_weight > 0, the score includes a penalty for each job that
     * finishes after its deadline. This makes NEH prefer sequences that meet
     * deadlines even if the raw makespan is slightly longer.
     */
    private function simulateMakespan(array $sequence, array $teamsData): float
    {
        $kayuAvail = array_fill(0, $teamsData['kayu_teams'], 0.0);
        $catAvail  = array_fill(0, $teamsData['cat_teams'], 0.0);
        $accAvail  = array_fill(0, $teamsData['acc_teams'], 0.0);

        // FIX #7: Read handoff buffer from config (defaults to 0 to preserve existing behaviour)
        $transDelay = (float) config('production.handoff_buffer_minutes', 0);

        $makespan        = 0.0;
        $completionTimes = [];

        foreach ($sequence as $idx => $job) {
            $earliestKayuIdx = array_search(min($kayuAvail), $kayuAvail);
            $startKayu       = $kayuAvail[$earliestKayuIdx];
            $endKayu         = $startKayu + $job['p_kayu'];
            $kayuAvail[$earliestKayuIdx] = $endKayu;

            $earliestCatIdx = array_search(min($catAvail), $catAvail);
            $startCat       = max($catAvail[$earliestCatIdx], $endKayu + $transDelay);
            $endCat         = $startCat + $job['p_cat'];
            $catAvail[$earliestCatIdx] = $endCat;

            $earliestAccIdx = array_search(min($accAvail), $accAvail);
            $startAcc       = max($accAvail[$earliestAccIdx], $endCat + $transDelay);
            $endAcc         = $startAcc + $job['p_acc'];
            $accAvail[$earliestAccIdx] = $endAcc;

            $completionTimes[$idx] = $endAcc;

            if ($endAcc > $makespan) {
                $makespan = $endAcc;
            }
        }

        $calendarPenalty  = config('production.calendar_penalty', 1.4);
        $tardinessWeight  = (float) config('production.tardiness_weight', 0);
        $tardinessPenalty = 0.0;

        if ($tardinessWeight > 0) {
            foreach ($completionTimes as $idx => $completionMinutes) {
                $item        = $sequence[$idx]['item'];
                $rawDeadline = $item->production_deadline
                    ?? $item->productionOrder->production_deadline;

                if (!$rawDeadline) {
                    continue;
                }

                $deadlineMinutes = Carbon::now()->diffInMinutes(Carbon::parse($rawDeadline), false);
                $tardiness       = max(0, ($completionMinutes * $calendarPenalty) - $deadlineMinutes);
                $tardinessPenalty += $tardiness;
            }
        }

        return ($makespan * $calendarPenalty) + ($tardinessPenalty * $tardinessWeight);
    }

    /**
     * FIX #8: Per-order material ETA replaces the single $materialArrived boolean flag.
     *
     * Previously dispatchSequence received a boolean that set one materialEta for the
     * entire batch. This was wrong for mixed batches: when Order A arrives and Order B
     * is already on_going, both were treated identically regardless of their status.
     *
     * Now: items whose order is on_going (material confirmed present) get materialEta = now()
     * so they slot in immediately. Items whose order is still await_material keep the
     * 14-day offset. Priority between the two groups falls out of EDD/NEH naturally —
     * no manual overrides needed.
     */
    private function dispatchSequence(array $sequence, int $factoryId): void
    {
        $kayuStation = Station::with('teams')->where('factory_location_id', $factoryId)->where('nama_station', 'kayu')->first();
        $catStation  = Station::with('teams')->where('factory_location_id', $factoryId)->where('nama_station', 'cat')->first();
        $accStation  = Station::with('teams')->where('factory_location_id', $factoryId)->where('nama_station', 'acc')->first();

        $kayuTeams = $kayuStation ? $kayuStation->teams->pluck('id')->toArray() : [];
        $catTeams  = $catStation  ? $catStation->teams->pluck('id')->toArray()  : [];
        $accTeams  = $accStation  ? $accStation->teams->pluck('id')->toArray()  : [];

        $now = now();
        $kayuAvail = $this->getActualTeamAvailabilities($kayuTeams, $now);
        $catAvail  = $this->getActualTeamAvailabilities($catTeams, $now);
        $accAvail  = $this->getActualTeamAvailabilities($accTeams, $now);

        // FIX #7: Consistent handoff buffer from config
        $transDelay = (float) config('production.handoff_buffer_minutes', 0);

        $orderDates = [];

        DB::transaction(function () use (
            $sequence, $kayuStation, $catStation, $accStation,
            &$kayuAvail, &$catAvail, &$accAvail,
            $transDelay, $now, &$orderDates
        ) {
            foreach ($sequence as $job) {
                $item    = $job['item'];
                $orderId = $item->production_order_id;

                // on_going = material confirmed, start as soon as a team is free.
                // await_material = material not yet here, hold for 14 days.
                $orderMaterialEta = $item->productionOrder->status_id === 'on_going'
                    ? $now->copy()
                    : $now->copy()->addDays(14);

                $kayuTeamId = $this->getEarliestAvailableTeam($kayuAvail);
                $startKayu  = $this->advanceToWorkingHours(max($kayuAvail[$kayuTeamId], $orderMaterialEta));
                $endKayu         = $this->addWorkingMinutes($startKayu, $job['p_kayu']);
                $kayuAvail[$kayuTeamId] = $this->getNextAvailability($endKayu);
                $this->createSchedule($item->id, $kayuStation->id, $kayuTeamId, $startKayu, $endKayu, 'pending');

                $catTeamId = $this->getEarliestAvailableTeam($catAvail);
                $delayCat  = $this->addWorkingMinutes($this->getNextAvailability($endKayu), $transDelay);
                $startCat  = $this->advanceToWorkingHours(max($catAvail[$catTeamId], $delayCat));
                $endCat    = $this->addWorkingMinutes($startCat, $job['p_cat']);
                $catAvail[$catTeamId] = $this->getNextAvailability($endCat);
                $this->createSchedule($item->id, $catStation->id, $catTeamId, $startCat, $endCat, 'pending');

                $accTeamId = $this->getEarliestAvailableTeam($accAvail);
                $delayAcc  = $this->addWorkingMinutes($this->getNextAvailability($endCat), $transDelay);
                $startAcc  = $this->advanceToWorkingHours(max($accAvail[$accTeamId], $delayAcc));
                $endAcc    = $this->addWorkingMinutes($startAcc, $job['p_acc']);
                $accAvail[$accTeamId] = $this->getNextAvailability($endAcc);
                $this->createSchedule($item->id, $accStation->id, $accTeamId, $startAcc, $endAcc, 'pending');

                if (!isset($orderDates[$orderId])) {
                    $orderDates[$orderId] = ['start' => $startKayu->copy(), 'end' => $endAcc->copy()];
                } else {
                    if ($startKayu->lt($orderDates[$orderId]['start'])) {
                        $orderDates[$orderId]['start'] = $startKayu->copy();
                    }
                    if ($endAcc->gt($orderDates[$orderId]['end'])) {
                        $orderDates[$orderId]['end'] = $endAcc->copy();
                    }
                }
            }
        });

        foreach ($orderDates as $orderId => $dates) {
            \App\Models\ProductionOrder::where('id', $orderId)->update([
                'production_start' => $dates['start'],
                'estimated_end'    => $dates['end'],
            ]);
        }
    }

    private function getTeamsDataForFactory(int $factoryId): array
    {
        // max(1, ...) prevents simulateMakespan from receiving 0, which would produce
        // an empty array_fill and cause min([]) to throw ValueError in PHP 8.1+.
        // A station that exists but has 0 teams assigned returns teams_count=0 (not null),
        // so the ?? fallback alone is insufficient.
        $kayuCount = max(1, Station::where('factory_location_id', $factoryId)->where('nama_station', 'kayu')->withCount('teams')->first()?->teams_count ?? 1);
        $catCount  = max(1, Station::where('factory_location_id', $factoryId)->where('nama_station', 'cat')->withCount('teams')->first()?->teams_count ?? 1);
        $accCount  = max(1, Station::where('factory_location_id', $factoryId)->where('nama_station', 'acc')->withCount('teams')->first()?->teams_count ?? 1);

        return [
            'is_priority' => true,
            'kayu_teams'  => $kayuCount,
            'cat_teams'   => $catCount,
            'acc_teams'   => $accCount,
        ];
    }

    /**
     * Team availability = end_time of latest schedule that still occupies the team
     * (pending or in_progress). Completed schedules are ignored — once done, the
     * team is free again. Cancelled schedules are likewise ignored. Without this
     * filter, a long-finished schedule from last year would block all new work.
     */
    private function getActualTeamAvailabilities(array $teamIds, Carbon $defaultTime): array
    {
        $availabilities = [];
        foreach ($teamIds as $teamId) {
            $latestSchedule = ProductionSchedule::where('team_id', $teamId)
                ->whereIn('status', ['pending', 'in_progress'])
                ->orderBy('end_time', 'desc')
                ->first();
            $availabilities[$teamId] = $latestSchedule
                ? Carbon::parse($latestSchedule->end_time)
                : $defaultTime->copy();
        }
        return $availabilities;
    }

    private function getEarliestAvailableTeam(array $availabilities)
    {
        $earliestTeamId = array_key_first($availabilities);
        $earliestTime   = $availabilities[$earliestTeamId];

        foreach ($availabilities as $teamId => $time) {
            if ($time->lt($earliestTime)) {
                $earliestTime   = $time;
                $earliestTeamId = $teamId;
            }
        }

        return $earliestTeamId;
    }

    private function createSchedule(int $itemId, int $stationId, int $teamId, Carbon $start, Carbon $end, string $status = 'in_progress'): void
    {
        ProductionSchedule::create([
            'production_order_item_id' => $itemId,
            'station_id'               => $stationId,
            'team_id'                  => $teamId,
            'start_time'               => $start,
            'end_time'                 => $end,
            'status'                   => $status,
            'actual_start'             => $status === 'in_progress' ? now() : null,
        ]);
    }

    private function advanceToWorkingHours(Carbon $time): Carbon
    {
        $time = $time->copy();

        if ($time->isWeekend()) {
            return $time->next(Carbon::MONDAY)->setTime(8, 0, 0);
        }

        if ($time->format('H:i:s') < '08:00:00') {
            return $time->setTime(8, 0, 0);
        }

        if ($time->format('H:i:s') >= '17:00:00') {
            $time->addDay()->setTime(8, 0, 0);
            if ($time->isWeekend()) {
                $time->next(Carbon::MONDAY)->setTime(8, 0, 0);
            }
        }

        return $time;
    }

    private function getNextAvailability(Carbon $endTime): Carbon
    {
        $cutoff = config('production.handoff_cutoff_time', '16:00:00');
        if ($endTime->format('H:i:s') >= $cutoff) {
            $nextDay = $endTime->copy()->addDay()->setTime(8, 0, 0);
            return $this->advanceToWorkingHours($nextDay);
        }
        return $endTime->copy();
    }

    private function addWorkingMinutes(Carbon $start, float $minutesToAdd): Carbon
    {
        $current = $this->advanceToWorkingHours($start->copy());
        $remaining = $minutesToAdd;

        while ($remaining > 0) {
            $endOfDay = $current->copy()->setTime(17, 0, 0);
            $availableMinutesToday = $current->diffInMinutes($endOfDay);

            if ($remaining <= $availableMinutesToday) {
                // Cast to int: the float subtraction below can leave a sub-nanosecond
                // residual (e.g. 5.68e-14) that Carbon::addMinutes() rejects.
                $current->addMinutes((int) round($remaining));
                $remaining = 0;
            } else {
                $remaining -= $availableMinutesToday;
                $current->addDay()->setTime(8, 0, 0);
                $current = $this->advanceToWorkingHours($current);
            }
        }

        return $current;
    }

    /**
     * Handle actual material arrival for an order.
     *
     * FIX #8: Corrected the three-phase sequence so status, deletion, and
     * rescheduling happen in the right order.
     *
     * Phase 1 — Transition first.
     *   The order is immediately set to on_going before any scheduling runs.
     *   This is required so dispatchSequence reads the correct status_id when
     *   computing per-order materialEta: on_going → now(), await_material → now+14d.
     *   Previously the status update happened last, so the arriving order's items
     *   were still seen as await_material during dispatch and got the wrong ETA.
     *
     * Phase 2 — Global pending-schedule wipe.
     *   All pending schedules across every unstarted item in await_material and
     *   on_going orders are deleted before rescheduling. Previously only the
     *   arriving order's pending slots were deleted; other orders' pending slots
     *   were left intact, causing duplicate schedule rows when dispatchSequence
     *   created new ones on top of them.
     *
     * Phase 3 — Full re-optimisation.
     *   All reschedulable items (unstarted, across all active orders) are
     *   re-sequenced together using EDD/NEH. Because the arriving order is already
     *   on_going (Phase 1), its items get materialEta = now() and slot in
     *   immediately. Higher-priority orders (earlier deadline, urgent flag) sort
     *   ahead of lower-priority ones automatically — e.g. Order A arriving after
     *   Order B will push B's pending items behind A without any manual override.
     *   Items already in_progress or completed are never touched.
     */
    public function confirmMaterialArrival(int $orderId): void
    {
        $this->withSchedulingLock(function () use ($orderId): void {
            $order = \App\Models\ProductionOrder::findOrFail($orderId);

            $undeducted = $order->items()
                ->whereHas('materials', fn($q) => $q->where('is_deducted', false))
                ->count();

            if ($undeducted > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'materials' => ["Masih ada {$undeducted} item yang materialnya belum dikurangi dari stok."],
                ]);
            }

            if (!$order->spk()->exists()) {
                $factoryId = FactoryLocation::first()?->id ?? 1;
                $this->spkService->createForProductionOrder($order, now()->toDateString(), $factoryId);
            }

            $order->update(['status_id' => 'on_going']);

            $this->rescheduleAllPending();
        });
    }

    /**
     * R6: Batch-confirm material arrival for multiple orders at once.
     * Performs ONE re-optimisation instead of N separate ones.
     */
    public function confirmMaterialArrivalBatch(array $orderIds): void
    {
        $this->withSchedulingLock(function () use ($orderIds): void {
            $unreadyOrders = \App\Models\ProductionOrder::whereIn('id', $orderIds)
                ->where('status_id', 'await_material')
                ->whereHas('items.materials', fn($q) => $q->where('is_deducted', false))
                ->pluck('order_id');

            if ($unreadyOrders->isNotEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'materials' => ['Material belum lengkap untuk order: ' . $unreadyOrders->join(', ')],
                ]);
            }

            $factoryId = FactoryLocation::first()?->id ?? 1;
            \App\Models\ProductionOrder::whereIn('id', $orderIds)
                ->where('status_id', 'await_material')
                ->get()
                ->each(function ($ord) use ($factoryId) {
                    if (!$ord->spk()->exists()) {
                        $this->spkService->createForProductionOrder($ord, now()->toDateString(), $factoryId);
                    }
                });

            \App\Models\ProductionOrder::whereIn('id', $orderIds)
                ->where('status_id', 'await_material')
                ->update(['status_id' => 'on_going']);

            $this->rescheduleAllPending();
        });
    }

    /**
     * Shared rescheduling logic used by both single and batch material arrival.
     *
     * Collects all reschedulable items (pending-only or unscheduled) across all
     * active orders, wipes their pending schedules, and runs a full EDD/NEH
     * re-optimisation.
     */
    private function rescheduleAllPending(): void
    {
        // Collect all items that only have pending schedules (or none).
        $allReschedulableItems = ProductionOrderItem::with(['productionOrder.spk.assignedFactory'])
            ->whereHas('productionOrder', function ($q) {
                $q->whereIn('status_id', ['await_material', 'on_going'])
                  ->whereNotNull('production_deadline');
            })
            ->where(function ($q) {
                // Items with no schedules at all, OR items whose schedules are all pending
                $q->whereDoesntHave('schedules')
                  ->orWhereDoesntHave('schedules', fn($sq) =>
                      $sq->whereIn('status', ['in_progress', 'completed'])
                  );
            })
            ->get();

        // Wipe pending slots AND re-optimise inside a SINGLE transaction. If any
        // factory fails readiness validation (or anything else throws) mid-reschedule,
        // the pending-schedule wipe rolls back too — so we can never end up with
        // schedules deleted but not recreated. (dispatchSequence opens its own nested
        // transaction, which Laravel runs as a savepoint within this one.)
        DB::transaction(function () use ($allReschedulableItems) {
            foreach ($allReschedulableItems as $item) {
                $item->schedules()->where('status', 'pending')->delete();
            }

            if ($allReschedulableItems->isNotEmpty()) {
                $grouped = $allReschedulableItems->groupBy(fn($item) =>
                    $item->productionOrder->spk?->assigned_factory ?? 1
                );

                foreach ($grouped as $factoryId => $factoryItems) {
                    $this->scheduleForFactory((int) $factoryId, $factoryItems);
                }
            }
        });

        app(ProductionScheduleService::class)->refreshQueues();
    }
}