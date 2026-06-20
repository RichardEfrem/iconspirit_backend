<?php

namespace App\Services\Production;

use App\Models\ProductionOrder;
use App\Models\ProductionSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class ProductionScheduleService
{
    // Same lock key SchedulingService uses, so manual status changes, the
    // auto-start tick, and full reschedules never mutate schedules concurrently.
    private const QUEUE_LOCK_KEY = 'scheduling:global';
    private const QUEUE_LOCK_TTL_SECONDS = 120;
    private const QUEUE_LOCK_WAIT_SECONDS = 10;

    /**
     * Run $callback while holding the global scheduling lock, blocking up to
     * QUEUE_LOCK_WAIT_SECONDS. Used by user-facing status changes so they wait
     * their turn rather than racing an auto-start tick or a reschedule.
     */
    private function withQueueLock(\Closure $callback)
    {
        $lock = Cache::lock(self::QUEUE_LOCK_KEY, self::QUEUE_LOCK_TTL_SECONDS);

        try {
            if (!$lock->block(self::QUEUE_LOCK_WAIT_SECONDS)) {
                throw new \DomainException(
                    'Penjadwalan sedang berjalan. Silakan coba lagi dalam beberapa detik.'
                );
            }
            return $callback();
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw new \DomainException(
                'Penjadwalan sedang berjalan. Silakan coba lagi dalam beberapa detik.'
            );
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Auto-start every team's next ready task. Invoked on a timer by the
     * production:auto-start-queues command so a station whose slot-time has
     * arrived (or whose blocker just cleared) transitions pending -> in_progress
     * without waiting for another completion event — closing the gap where the
     * event-driven cascade leaves a station stranded as a manual "Mulai".
     *
     * Uses a NON-blocking lock: if a reschedule or manual status change holds the
     * lock, skip this tick rather than queueing — the next minute's tick retries.
     * Returns the number of stations started, for the command's output.
     */
    public function autoStartReadyQueues(): int
    {
        $lock = Cache::lock(self::QUEUE_LOCK_KEY, self::QUEUE_LOCK_TTL_SECONDS);

        if (!$lock->get()) {
            return 0;
        }

        try {
            $before = ProductionSchedule::where('status', 'in_progress')->count();
            $this->refreshQueues();
            $after = ProductionSchedule::where('status', 'in_progress')->count();

            $started = max(0, $after - $before);
            if ($started > 0) {
                $this->flushScheduleCaches();
            }

            return $started;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Get all schedules for a production order (via its items).
     */
    public function getSchedulesByOrderId(int $orderId): array
    {
        $order = ProductionOrder::with([
            'items.schedules.station',
            'items.schedules.team',
            'items.product',
        ])->findOrFail($orderId);

        $allSchedules = $order->items->flatMap(fn($item) => $item->schedules);
        $itemIds = $order->items->pluck('id');
        $canStartMap = $this->bulkCanStart($allSchedules, $itemIds);

        $schedules = [];
        foreach ($order->items as $item) {
            foreach ($item->schedules as $schedule) {
                $schedules[] = [
                    'id' => $schedule->id,
                    'production_order_item_id' => $schedule->production_order_item_id,
                    'station_id' => $schedule->station_id,
                    'team_id' => $schedule->team_id,
                    'start_time' => $schedule->start_time?->toISOString(),
                    'end_time' => $schedule->end_time?->toISOString(),
                    'status' => $schedule->status,
                    'station' => $schedule->station ? [
                        'id' => $schedule->station->id,
                        'nama_station' => $schedule->station->nama_station,
                        'factory_location_id' => $schedule->station->factory_location_id,
                    ] : null,
                    'team' => $schedule->team ? [
                        'id' => $schedule->team->id,
                        'kode_team' => $schedule->team->kode_team,
                    ] : null,
                    'can_start' => $canStartMap[$schedule->id] ?? false,
                    'item' => [
                        'id' => $item->id,
                        'product_name' => $item->product?->nama_product ?? 'Unknown',
                        'panjang' => $item->panjang,
                        'tinggi' => $item->tinggi,
                        'quantity' => $item->quantity,
                    ],
                ];
            }
        }

        return $schedules;
    }

    /**
     * Get all schedules for every await_material order in one query, keyed by order ID.
     * Replaces N individual getSchedulesByOrderId calls on the schedules calendar page.
     *
     * @return array<int, array> Map of production_order.id => schedule records
     */
    public function getSchedulesForAwaitMaterialOrders(): array
    {
        $orders = ProductionOrder::with([
            'items.schedules.station',
            'items.schedules.team',
            'items.product',
        ])->where('status_id', 'await_material')->get();

        $allSchedules = $orders->flatMap(fn($o) => $o->items->flatMap(fn($i) => $i->schedules));
        $allItemIds   = $orders->flatMap(fn($o) => $o->items->pluck('id'));
        $canStartMap  = $this->bulkCanStart($allSchedules, $allItemIds);

        $result = [];
        foreach ($orders as $order) {
            $orderSchedules = [];
            foreach ($order->items as $item) {
                foreach ($item->schedules as $schedule) {
                    $orderSchedules[] = [
                        'id'                        => $schedule->id,
                        'production_order_item_id'  => $schedule->production_order_item_id,
                        'station_id'                => $schedule->station_id,
                        'team_id'                   => $schedule->team_id,
                        'start_time'                => $schedule->start_time?->toISOString(),
                        'end_time'                  => $schedule->end_time?->toISOString(),
                        'status'                    => $schedule->status,
                        'station'                   => $schedule->station ? [
                            'id'                  => $schedule->station->id,
                            'nama_station'        => $schedule->station->nama_station,
                            'factory_location_id' => $schedule->station->factory_location_id,
                        ] : null,
                        'team'                      => $schedule->team ? [
                            'id'        => $schedule->team->id,
                            'kode_team' => $schedule->team->kode_team,
                        ] : null,
                        'can_start'                 => $canStartMap[$schedule->id] ?? false,
                        'item'                      => [
                            'id'                => $item->id,
                            'product_name'      => $item->product?->nama_product ?? 'Unknown',
                            'panjang'           => $item->panjang,
                            'tinggi'            => $item->tinggi,
                            'quantity'          => $item->quantity,
                        ],
                    ];
                }
            }
            $result[$order->id] = $orderSchedules;
        }

        return $result;
    }

    /**
     * Get aggregated progress for a production order.
     */
    public function getOrderProgress(int $orderId): array
    {
        $order = ProductionOrder::with([
            'items.schedules.station',
            'spk.assignedFactory',
        ])->findOrFail($orderId);

        return $this->buildProgressArray($order);
    }

    /**
     * Get progress for all orders in on_going status (for dashboard).
     */
    public function getAllOngoingProgress(): array
    {
        return Cache::remember('ongoing_progress', 15, function () {
            $orders = ProductionOrder::with([
                'items.schedules.station',
                'spk.assignedFactory',
            ])->where('status_id', 'on_going')->get();

            return $orders->map(fn($order) => $this->buildProgressArray($order))->toArray();
        });
    }

    /**
     * Get all ongoing schedules enriched with team/order/item info for Gantt view.
     */
    public function getOngoingTeamSchedules(): array
    {
        $schedules = ProductionSchedule::with([
            'station',
            'team',
            'orderItem.product',
            'orderItem.productionOrder',
        ])
            ->whereHas('orderItem.productionOrder', fn($q) => $q->where('status_id', 'on_going'))
            ->orderBy('start_time', 'asc')
            ->get();

        $itemIds = $schedules->pluck('production_order_item_id')->unique()->values();
        $canStartMap = $this->bulkCanStart($schedules, $itemIds);

        return $schedules->map(function ($s) use ($canStartMap) {
            return [
                'id' => $s->id,
                'team_id' => $s->team_id,
                'team_code' => $s->team?->kode_team ?? 'Unknown',
                'station_id' => $s->station_id,
                'station_name' => $s->station?->nama_station ?? 'Unknown',
                'status' => $s->status,
                'start_time' => $s->start_time?->toISOString(),
                'end_time' => $s->end_time?->toISOString(),
                'actual_start' => $s->actual_start?->toISOString(),
                'actual_end' => $s->actual_end?->toISOString(),
                'can_start' => $canStartMap[$s->id] ?? false,
                'order_id' => $s->orderItem?->productionOrder?->id,
                'order_code' => $s->orderItem?->productionOrder?->order_id ?? '-',
                'nama_customer' => $s->orderItem?->productionOrder?->nama_customer ?? '-',
                'item_id' => $s->orderItem?->id,
                'product_name' => $s->orderItem?->product?->nama_product ?? 'Unknown',
                'spesifikasi' => '',
                'quantity' => $s->orderItem?->quantity ?? 0,
            ];
        })->toArray();
    }

    /**
     * Iterate through all teams and start their next ready pending task.
     */
    public function refreshQueues(): void
    {
        $teamIds = \App\Models\Team::pluck('id');
        foreach ($teamIds as $teamId) {
            $this->startNextInQueue($teamId);
        }
    }

    /**
     * Get summary data for all finished orders with cost analysis.
     */
    public function getFinishedOrdersSummary(): array
    {
        return Cache::remember('finished_orders_summary', 300, fn() => $this->buildFinishedOrdersSummary());
    }

    private function buildFinishedOrdersSummary(): array
    {
        $workMinPerDay = config('production.work_minutes_per_day');

        $orders = ProductionOrder::with([
            'items.schedules.station',
            'items.materials.material',
            'spk.assignedFactory',
        ])->where('status_id', 'finished')->get();

        $results = [];

        foreach ($orders as $order) {
            $materialCost = 0;
            $estimatedLaborCost = 0;
            $actualLaborCost = 0;
            $estimatedDurationMinutes = 0;
            $actualDurationMinutes = 0;
            $earliestStart = null;
            $latestEnd = null;
            $actualEarliestStart = null;
            $actualLatestEnd = null;

            foreach ($order->items as $item) {
                // Sum material costs
                foreach ($item->materials as $mat) {
                    $materialCost += (float) $mat->cost;
                }

                foreach ($item->schedules as $schedule) {
                    $biayaHarian = $schedule->station?->biaya_harian ?? 0;

                    // Estimated: use scheduled start_time and end_time
                    $scheduledMins = $schedule->start_time && $schedule->end_time
                        ? $schedule->start_time->diffInMinutes($schedule->end_time)
                        : 0;
                    $estimatedDurationMinutes += $scheduledMins;
                    $estimatedDays = $workMinPerDay > 0 ? $scheduledMins / $workMinPerDay : 0;
                    $estimatedLaborCost += $estimatedDays * $biayaHarian;

                    // Actual: use actual_start and actual_end
                    $actualMins = $schedule->actual_start && $schedule->actual_end
                        ? $schedule->actual_start->diffInMinutes($schedule->actual_end)
                        : $scheduledMins; // fallback to estimated if no actual data
                    $actualDurationMinutes += $actualMins;
                    $actualDays = $workMinPerDay > 0 ? $actualMins / $workMinPerDay : 0;
                    $actualLaborCost += $actualDays * $biayaHarian;

                    // Track earliest/latest for scheduled times
                    if ($schedule->start_time && (!$earliestStart || $schedule->start_time->lt($earliestStart))) {
                        $earliestStart = $schedule->start_time;
                    }
                    if ($schedule->end_time && (!$latestEnd || $schedule->end_time->gt($latestEnd))) {
                        $latestEnd = $schedule->end_time;
                    }

                    // Track earliest/latest for actual times
                    if ($schedule->actual_start && (!$actualEarliestStart || $schedule->actual_start->lt($actualEarliestStart))) {
                        $actualEarliestStart = $schedule->actual_start;
                    }
                    if ($schedule->actual_end && (!$actualLatestEnd || $schedule->actual_end->gt($actualLatestEnd))) {
                        $actualLatestEnd = $schedule->actual_end;
                    }
                }
            }

            $results[] = [
                'order_id' => $order->id,
                'order_code' => $order->order_id,
                'nama_customer' => $order->nama_customer,
                'tanggal_order' => $order->tanggal_order,
                'production_deadline' => $order->production_deadline,
                'production_start' => $order->production_start?->toISOString(),
                'estimated_end' => $order->estimated_end?->toISOString(),
                'factory_name' => $order->spk?->assignedFactory?->nama_factory ?? 'Unassigned',
                'factory_priority' => $order->spk?->assignedFactory?->priority ?? null,
                'total_items' => $order->items->count(),
                // Timing
                'estimated_duration_days' => $workMinPerDay > 0 ? round($estimatedDurationMinutes / $workMinPerDay, 1) : 0,
                'actual_duration_days' => $workMinPerDay > 0 ? round($actualDurationMinutes / $workMinPerDay, 1) : 0,
                'scheduled_start' => $earliestStart?->toISOString(),
                'scheduled_end' => $latestEnd?->toISOString(),
                'actual_start' => $actualEarliestStart?->toISOString(),
                'actual_end' => $actualLatestEnd?->toISOString(),
                // Costs
                'material_cost' => round($materialCost),
                'estimated_labor_cost' => round($estimatedLaborCost),
                'actual_labor_cost' => round($actualLaborCost),
                'estimated_total_cost' => round($materialCost + $estimatedLaborCost),
                'actual_total_cost' => round($materialCost + $actualLaborCost),
            ];
        }

        return $results;
    }

    /**
     * Update the status of a schedule record.
     * Only valid transition: in_progress → completed.
     * On completion, auto-promote the next station for the same item.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function updateScheduleStatus(int $scheduleId, string $newStatus): ProductionSchedule
    {
        return $this->withQueueLock(function () use ($scheduleId, $newStatus) {
            return $this->applyScheduleStatus($scheduleId, $newStatus);
        });
    }

    /**
     * Status-transition body. Always invoked under withQueueLock so its reflow /
     * promote / startNextInQueue side-effects never race an auto-start tick.
     */
    private function applyScheduleStatus(int $scheduleId, string $newStatus): ProductionSchedule
    {
        $schedule = ProductionSchedule::with('station')->findOrFail($scheduleId);

        $validTransitions = [
            'pending' => ['in_progress'],
            'in_progress' => ['completed'],
            'completed' => [],
        ];

        $allowed = $validTransitions[$schedule->status] ?? [];

        if (!in_array($newStatus, $allowed)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition from '{$schedule->status}' to '{$newStatus}'."],
            ]);
        }

        if ($newStatus === 'in_progress') {
            $this->transitionToInProgress($schedule);
        } elseif ($newStatus === 'completed') {
            $now = now();
            $schedule->update([
                'status' => 'completed',
                'actual_end' => $now,
                // Record actual completion time so downstream rescheduling has an accurate base
                'end_time' => $now,
            ]);
        }

        if ($newStatus === 'completed') {
            // 1. Re-chain the still-pending downstream stations onto the new times
            // FIRST. On an early finish, this pulls the next station's start_time
            // back to the actual completion time. promoteNextStation's canStart
            // check gates on "now >= start_time", so the window must be updated
            // before we attempt the promotion — otherwise the stale (later) slot
            // blocks an immediate hand-off and forces a manual start.
            $this->reflowItemSchedules($schedule->production_order_item_id);

            // 2. Try to promote this ITEM to its next station (kayu -> cat -> acc)
            $this->promoteNextStation($schedule);

            // 3. Try to start the next ITEM for this TEAM (because this team is now free)
            $this->startNextInQueue($schedule->team_id);

            // Check if all schedules for the order are completed → auto-finish
            $this->checkAndAutoFinishOrder($schedule);

            // Invalidate caches
            $this->flushScheduleCaches();
        }

        return $schedule->fresh(['station', 'team', 'orderItem']);
    }

    /**
     * Flush caches affected by schedule status changes.
     */
    private function flushScheduleCaches(): void
    {
        Cache::forget('ongoing_progress');
        Cache::forget('finished_orders_summary');
    }

    /**
     * Transition a pending schedule to in_progress, shifting its scheduled window
     * to the next valid working slot while preserving the original working-minutes duration.
     * Raw diffInSeconds is wrong for multi-day schedules because it counts overnight
     * non-working hours; computeWorkingMinutes extracts only the actual work time.
     */
    private function transitionToInProgress(ProductionSchedule $schedule): void
    {
        $now = now();

        $workingMinutes = ($schedule->start_time && $schedule->end_time)
            ? $this->computeWorkingMinutes($schedule->start_time, $schedule->end_time)
            : 0;

        $workingStart = $this->advanceToWorkingHours($now->copy());

        $schedule->update([
            'status'       => 'in_progress',
            'actual_start' => $now,
            'start_time'   => $workingStart,
            'end_time'     => $workingMinutes > 0
                ? $this->addWorkingMinutes($workingStart->copy(), $workingMinutes)
                : $schedule->end_time,
        ]);

        // The window for this station just moved. Re-chain the still-pending
        // downstream stations of the same item so their estimates follow it
        // (e.g. acc must start after cat's new end, not keep its stale slot).
        $this->reflowItemSchedules($schedule->production_order_item_id);
    }

    /**
     * Re-chain the pending stations of an item so each follows the actual/updated
     * end of the station before it (kayu → cat → acc).
     *
     * Called whenever an upstream station's window changes (manual start, early
     * finish, auto-promotion). Completed and in_progress stations are anchors and
     * are never moved; only pending stations are re-timed, preserving each one's
     * original working-minute duration. Without this, finishing kayu early shifts
     * cat earlier but leaves acc sitting at its old slot, disconnected from cat.
     */
    private function reflowItemSchedules(int $itemId): void
    {
        $stationOrder = ['kayu', 'cat', 'acc'];
        $transDelay = (float) config('production.handoff_buffer_minutes', 0);

        $schedules = ProductionSchedule::with('station')
            ->where('production_order_item_id', $itemId)
            ->get()
            ->keyBy(fn($s) => $s->station?->nama_station);

        $prevEnd = null;

        foreach ($stationOrder as $name) {
            $sched = $schedules->get($name);
            if (!$sched) {
                continue;
            }

            // Anchors: keep their times, just carry forward the end as the next chain point.
            if ($sched->status === 'completed' || $sched->status === 'in_progress') {
                if ($sched->end_time) {
                    $prevEnd = $sched->end_time->copy();
                }
                continue;
            }

            // Pending with no upstream anchor yet (e.g. kayu) — leave as scheduled.
            if ($prevEnd === null) {
                if ($sched->end_time) {
                    $prevEnd = $sched->end_time->copy();
                }
                continue;
            }

            $duration = ($sched->start_time && $sched->end_time)
                ? $this->computeWorkingMinutes($sched->start_time, $sched->end_time)
                : 0.0;

            $base = $this->getNextAvailability($prevEnd->copy());
            if ($transDelay > 0) {
                $base = $this->addWorkingMinutes($base, $transDelay);
            }
            $newStart = $this->advanceToWorkingHours($base);
            $newEnd = $duration > 0
                ? $this->addWorkingMinutes($newStart->copy(), $duration)
                : $newStart->copy();

            $sched->update(['start_time' => $newStart, 'end_time' => $newEnd]);
            $prevEnd = $newEnd->copy();
        }
    }

    /**
     * A station should not begin in the last working hours of a day; if the
     * previous station finishes at or after the configured cutoff (default
     * 16:00), the next one starts the following working morning. Mirrors
     * SchedulingService's dispatch rule.
     */
    private function getNextAvailability(Carbon $endTime): Carbon
    {
        $cutoff = config('production.handoff_cutoff_time', '16:00:00');
        if ($endTime->format('H:i:s') >= $cutoff) {
            return $this->advanceToWorkingHours($endTime->copy()->addDay()->setTime(8, 0, 0));
        }
        return $endTime->copy();
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

    private function addWorkingMinutes(Carbon $start, float $minutesToAdd): Carbon
    {
        $current = $this->advanceToWorkingHours($start->copy());
        $remaining = $minutesToAdd;

        while ($remaining > 0) {
            $endOfDay = $current->copy()->setTime(17, 0, 0);
            $availableMinutesToday = $current->diffInMinutes($endOfDay);

            if ($remaining <= $availableMinutesToday) {
                // round (not ceil) to match SchedulingService::addWorkingMinutes — keeps
                // a reflowed schedule identical to its original dispatch and avoids a
                // spurious +1 min from sub-nanosecond float residue (e.g. 540.0000001).
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
     * Count only the minutes within working hours (08:00–17:00, Mon–Fri)
     * between two timestamps. Used to extract true work duration from a
     * scheduled window that may span overnights or weekends.
     */
    private function computeWorkingMinutes(Carbon $start, Carbon $end): float
    {
        if ($end->lte($start)) {
            return 0.0;
        }

        $minutes = 0.0;
        $current = $start->copy();

        if ($current->format('H:i:s') < '08:00:00') {
            $current->setTime(8, 0, 0);
        }

        while ($current->lt($end)) {
            if ($current->isWeekend()) {
                $current->next(Carbon::MONDAY)->setTime(8, 0, 0);
                continue;
            }

            if ($current->format('H:i:s') >= '17:00:00') {
                $current->addDay()->setTime(8, 0, 0);
                continue;
            }

            $dayEnd = $current->copy()->setTime(17, 0, 0);
            $sliceEnd = $dayEnd->gt($end) ? $end->copy() : $dayEnd->copy();

            if ($sliceEnd->gt($current)) {
                $minutes += $current->diffInSeconds($sliceEnd) / 60.0;
            }

            $current->addDay()->setTime(8, 0, 0);
        }

        return $minutes;
    }

    /**
     * When a station is completed, auto-promote the next station to in_progress.
     * Flow: kayu → cat → acc
     */
    private function promoteNextStation(ProductionSchedule $completedSchedule): void
    {
        $stationName = $completedSchedule->station?->nama_station;
        if (!$stationName) return;

        $nextStationMap = [
            'kayu' => 'cat',
            'cat' => 'acc',
        ];

        $nextStationName = $nextStationMap[$stationName] ?? null;
        if (!$nextStationName) return;

        // Find the next station's schedule for the same item
        $nextSchedule = ProductionSchedule::where('production_order_item_id', $completedSchedule->production_order_item_id)
            ->whereHas('station', function ($q) use ($nextStationName) {
                $q->where('nama_station', $nextStationName);
            })
            ->where('status', 'pending')
            ->first();

        if ($nextSchedule && $this->canStart($nextSchedule)) {
            $this->transitionToInProgress($nextSchedule);
        }
    }

    /**
     * If all schedules for an order are completed, auto-set order to finished.
     */
    private function checkAndAutoFinishOrder(ProductionSchedule $schedule): void
    {
        $orderItem = $schedule->orderItem;
        if (!$orderItem) return;

        $order = $orderItem->productionOrder;
        if (!$order || $order->status_id !== 'on_going') return;

        // Check if ALL items in the order have ALL schedules completed
        $allItemIds = $order->items()->pluck('id');
        $totalSchedules = ProductionSchedule::whereIn('production_order_item_id', $allItemIds)->count();
        $completedSchedules = ProductionSchedule::whereIn('production_order_item_id', $allItemIds)
            ->where('status', 'completed')
            ->count();

        if ($totalSchedules > 0 && $totalSchedules === $completedSchedules) {
            $order->update(['status_id' => 'finished']);
            Cache::forget('finished_orders_summary');
        }
    }

    /**
     * Start the next pending task for a team if they are free.
     */
    private function startNextInQueue(int $teamId): void
    {
        $nextSchedule = ProductionSchedule::with('station')
            ->where('team_id', $teamId)
            ->where('status', 'pending')
            ->whereHas('orderItem.productionOrder', function ($q) {
                $q->where('status_id', 'on_going');
            })
            ->orderBy('start_time', 'asc')
            ->first();

        if (!$nextSchedule) {
            return;
        }

        // The team just freed up. This next task may still be parked at a stale
        // future slot it inherited from the predecessor it was chained behind —
        // and that predecessor has just finished early. Pull it back so the team
        // doesn't sit idle until the original planned time; otherwise canStart's
        // "now >= start_time" gate keeps the freed team waiting.
        $this->pullReadyScheduleToNow($nextSchedule);

        if ($this->canStart($nextSchedule)) {
            $this->transitionToInProgress($nextSchedule);
        }
    }

    /**
     * Pull a ready-but-future task back to the earliest feasible working slot
     * when its team has freed up early. Only acts if the task is otherwise
     * startable — no earlier unfinished task for the same team, and (for cat/acc)
     * its upstream station is already complete. Preserves the task's working-minute
     * duration and only ever moves it EARLIER, never later.
     *
     * Without this, finishing one item early leaves the next item in that team's
     * queue stranded at its old slot: the team idles even though it is free and
     * the work is ready. This is the team-queue analogue of reflowItemSchedules,
     * which only re-chains stations within a single item.
     */
    private function pullReadyScheduleToNow(ProductionSchedule $schedule): void
    {
        if ($schedule->status !== 'pending' || !$schedule->start_time) {
            return;
        }

        // Never jump ahead of an earlier unfinished task for the same team.
        $hasEarlierForTeam = ProductionSchedule::where('team_id', $schedule->team_id)
            ->where('start_time', '<', $schedule->start_time)
            ->where('status', '!=', 'completed')
            ->whereHas('orderItem.productionOrder', fn($q) => $q->where('status_id', 'on_going'))
            ->exists();
        if ($hasEarlierForTeam) {
            return;
        }

        // Earliest feasible start: now — but never before the upstream station ends.
        $earliest = $this->advanceToWorkingHours(now());

        $stationName = $schedule->station?->nama_station;
        $prevStationMap = ['cat' => 'kayu', 'acc' => 'cat'];
        if (isset($prevStationMap[$stationName])) {
            $prev = ProductionSchedule::where('production_order_item_id', $schedule->production_order_item_id)
                ->whereHas('station', fn($q) => $q->where('nama_station', $prevStationMap[$stationName]))
                ->first();

            // Upstream not finished — canStart will (correctly) block it; leave the
            // current estimate untouched rather than pulling it to an invalid slot.
            if (!$prev || $prev->status !== 'completed' || !$prev->end_time) {
                return;
            }

            $transDelay = (float) config('production.handoff_buffer_minutes', 0);
            $base = $this->getNextAvailability($prev->end_time->copy());
            if ($transDelay > 0) {
                $base = $this->addWorkingMinutes($base, $transDelay);
            }
            $earliest = $this->advanceToWorkingHours($base->max(now()));
        }

        // Only pull earlier; never push a task later than its current plan.
        if ($earliest->gte($schedule->start_time)) {
            return;
        }

        $duration = $schedule->end_time
            ? $this->computeWorkingMinutes($schedule->start_time, $schedule->end_time)
            : 0.0;
        $newEnd = $duration > 0
            ? $this->addWorkingMinutes($earliest->copy(), $duration)
            : $earliest->copy();

        $schedule->update(['start_time' => $earliest, 'end_time' => $newEnd]);
    }

    /**
     * Check if a pending schedule is ready to be started.
     */
    private function canStart(ProductionSchedule $schedule): bool
    {
        if ($schedule->status !== 'pending') {
            return false;
        }

        // 0. The scheduled start slot must have arrived. A schedule pushed to a
        // later working day (e.g. material arriving on a weekend, so the slot is
        // moved to Monday) is not startable until that slot, even if the team is
        // free and the previous station is done.
        if ($schedule->start_time && now()->lt($schedule->start_time)) {
            return false;
        }

        // 1. Check if there are any earlier schedules for the SAME TEAM that are not completed.
        // Only count schedules from on_going orders — await_material/pending orders must not
        // block a team's active queue.
        $earlierSchedulesCount = ProductionSchedule::where('team_id', $schedule->team_id)
            ->where('start_time', '<', $schedule->start_time)
            ->where('status', '!=', 'completed')
            ->whereHas('orderItem.productionOrder', function ($q) {
                $q->where('status_id', 'on_going');
            })
            ->count();

        if ($earlierSchedulesCount > 0) {
            return false;
        }

        // 2. If it's NOT the first station, check if previous station of the SAME ITEM is completed.
        $stationName = $schedule->station?->nama_station;
        if ($stationName === 'cat' || $stationName === 'acc') {
            $prevStationMap = ['cat' => 'kayu', 'acc' => 'cat'];
            $prevStationName = $prevStationMap[$stationName];

            $prevCompleted = ProductionSchedule::where('production_order_item_id', $schedule->production_order_item_id)
                ->whereHas('station', function ($q) use ($prevStationName) {
                    $q->where('nama_station', $prevStationName);
                })
                ->where('status', 'completed')
                ->exists();

            if (!$prevCompleted) {
                return false;
            }
        }

        return true;
    }

    private function buildProgressArray(ProductionOrder $order): array
    {
        $totalSchedules = 0;
        $completedSchedules = 0;
        $inProgressSchedules = 0;
        $totalMinutes = 0.0;
        $completedMinutes = 0.0;
        $latestEndTime = null;
        $currentStage = 'scheduled';

        $stationProgress = [
            'kayu' => ['total' => 0, 'completed' => 0, 'in_progress' => 0],
            'cat'  => ['total' => 0, 'completed' => 0, 'in_progress' => 0],
            'acc'  => ['total' => 0, 'completed' => 0, 'in_progress' => 0],
        ];

        foreach ($order->items as $item) {
            foreach ($item->schedules as $schedule) {
                $totalSchedules++;
                $stationName = $schedule->station?->nama_station ?? 'unknown';
                $scheduleDuration = $schedule->start_time && $schedule->end_time
                    ? $schedule->start_time->diffInMinutes($schedule->end_time)
                    : 0;

                $totalMinutes += $scheduleDuration;

                if (isset($stationProgress[$stationName])) {
                    $stationProgress[$stationName]['total']++;
                }

                if ($schedule->status === 'completed') {
                    $completedSchedules++;
                    $completedMinutes += $scheduleDuration;
                    if (isset($stationProgress[$stationName])) {
                        $stationProgress[$stationName]['completed']++;
                    }
                } elseif ($schedule->status === 'in_progress') {
                    $inProgressSchedules++;
                    if (isset($stationProgress[$stationName])) {
                        $stationProgress[$stationName]['in_progress']++;
                    }
                }

                if ($schedule->end_time && (!$latestEndTime || $schedule->end_time->gt($latestEndTime))) {
                    $latestEndTime = $schedule->end_time;
                }
            }
        }

        if ($totalSchedules === 0) {
            $currentStage = 'not_scheduled';
        } elseif ($completedSchedules === $totalSchedules) {
            $currentStage = 'completed';
        } elseif ($inProgressSchedules > 0) {
            foreach (['kayu', 'cat', 'acc'] as $stage) {
                if ($stationProgress[$stage]['in_progress'] > 0) {
                    $currentStage = $stage;
                    break;
                }
            }
        } else {
            foreach (['kayu', 'cat', 'acc'] as $stage) {
                if ($stationProgress[$stage]['completed'] < $stationProgress[$stage]['total']) {
                    $currentStage = $stage;
                    break;
                }
            }
        }

        $progressPercent = $totalMinutes > 0
            ? round(($completedMinutes / $totalMinutes) * 100, 1)
            : 0;

        return [
            'order_id'              => $order->id,
            'order_code'            => $order->order_id,
            'nama_customer'         => $order->nama_customer,
            'production_deadline'   => $order->production_deadline,
            'production_start'      => $order->production_start?->toISOString(),
            'estimated_end'         => $order->estimated_end?->toISOString(),
            'factory_name'          => $order->spk?->assignedFactory?->nama_factory ?? 'Unassigned',
            'factory_priority'      => $order->spk?->assignedFactory?->priority ?? null,
            'total_items'           => $order->items->count(),
            'total_schedules'       => $totalSchedules,
            'completed_schedules'   => $completedSchedules,
            'in_progress_schedules' => $inProgressSchedules,
            'pending_count'         => $totalSchedules - $completedSchedules - $inProgressSchedules,
            'progress_percent'      => $progressPercent,
            'current_stage'         => $currentStage,
            'estimated_completion'  => $latestEndTime?->toISOString(),
            'station_progress'      => $stationProgress,
        ];
    }

    /**
     * Compute canStart for a collection of schedules in 2 queries instead of 2 per schedule.
     * Returns a map of schedule_id => bool.
     */
    private function bulkCanStart(
        \Illuminate\Support\Collection $schedules,
        \Illuminate\Support\Collection $itemIds
    ): array {
        $pendingSchedules = $schedules->where('status', 'pending');

        if ($pendingSchedules->isEmpty()) {
            return $schedules->pluck('id')->mapWithKeys(fn($id) => [$id => false])->toArray();
        }

        $teamIds = $pendingSchedules->pluck('team_id')->unique()->values();

        // Per team: earliest start_time of any non-completed on_going schedule (2 queries total)
        $teamEarliestPending = ProductionSchedule::whereIn('team_id', $teamIds)
            ->where('status', '!=', 'completed')
            ->whereHas('orderItem.productionOrder', fn($q) => $q->where('status_id', 'on_going'))
            ->selectRaw('team_id, MIN(start_time) as min_start')
            ->groupBy('team_id')
            ->pluck('min_start', 'team_id');

        // Completed (item_id + station_name) pairs as a fast lookup set
        $completedKeys = ProductionSchedule::whereIn('production_order_item_id', $itemIds)
            ->where('status', 'completed')
            ->join('station', 'production_schedules.station_id', '=', 'station.id')
            ->selectRaw('production_schedules.production_order_item_id, station.nama_station')
            ->get()
            ->mapWithKeys(fn($r) => [$r->production_order_item_id . '_' . $r->nama_station => true])
            ->toArray();

        $now = Carbon::now();

        $result = [];
        foreach ($schedules as $schedule) {
            if ($schedule->status !== 'pending') {
                $result[$schedule->id] = false;
                continue;
            }

            // Check 0: the scheduled start slot must have arrived. A schedule
            // pushed to a later working day (e.g. material arriving on a weekend,
            // so the slot is moved to Monday) is not startable until that slot.
            if ($schedule->start_time && $now->lt($schedule->start_time)) {
                $result[$schedule->id] = false;
                continue;
            }

            // Check 1: no earlier non-completed on_going schedule for this team
            $teamMin = $teamEarliestPending->get($schedule->team_id);
            if ($teamMin && $schedule->start_time && Carbon::parse($teamMin)->lt($schedule->start_time)) {
                $result[$schedule->id] = false;
                continue;
            }

            // Check 2: previous station completed for same item
            $stationName = $schedule->station?->nama_station;
            if ($stationName === 'cat' || $stationName === 'acc') {
                $prevMap = ['cat' => 'kayu', 'acc' => 'cat'];
                $prevKey = $schedule->production_order_item_id . '_' . $prevMap[$stationName];
                if (!isset($completedKeys[$prevKey])) {
                    $result[$schedule->id] = false;
                    continue;
                }
            }

            $result[$schedule->id] = true;
        }

        return $result;
    }
}
