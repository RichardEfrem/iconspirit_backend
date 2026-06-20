<?php

/*
|--------------------------------------------------------------------------
| Scenario Robustness Tests
|--------------------------------------------------------------------------
|
| Four "what if the real world happens" scenarios that the IconSpirit
| scheduling engine must survive gracefully:
|
|   1. Urgent order arrives while production is already running
|   2. Material arrives EARLY (order pulled forward)
|   3. Material is DELAYED, a ready order overtakes the delayed one
|   4. Many orders, limited teams: deadline-driven priority under contention
|   5. A station finishes EARLY, downstream stations reflow to follow
|
| Each test prints a before/after results table and asserts the engine
| produced the correct plan. Run with:
|
|   php artisan test --filter=ScenarioRobustnessTest
|
*/

use App\Models\FactoryLocation;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionSchedule;
use App\Models\ProductionStatus;
use App\Models\Spk;
use App\Models\Station;
use App\Models\Team;
use App\Services\Production\ProductionOrderService;
use App\Services\Production\ProductionScheduleService;
use App\Services\Production\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/* ---------------------------------------------------------------------- */
/*  Shared master-data setup                                              */
/* ---------------------------------------------------------------------- */

beforeEach(function () {
    // Freeze "now" to a Monday 08:00 so working-hour maths is deterministic.
    Carbon::setTestNow(Carbon::parse('2026-06-08 08:00:00')); // Monday

    ProductionStatus::insert([
        ['id' => 'new',            'label' => 'New',            'color' => 'blue'],
        ['id' => 'await_material', 'label' => 'Await Material', 'color' => 'orange'],
        ['id' => 'on_going',       'label' => 'On Going',       'color' => 'green'],
        ['id' => 'finished',       'label' => 'Finished',       'color' => 'gray'],
    ]);

    Product::create(['id' => 1, 'kode_product' => 'PRD-01', 'nama_product' => 'Standard Door']);

    // Pabrik A — the ONLY factory, mirroring the real seeders:
    //   FactoryLocationSeeder → just Pabrik A (id 1)
    //   StationSeeder         → kayu / cat / acc
    //   TeamSeeder            → 6 kayu, 6 cat, 3 acc  (acc is the bottleneck)
    FactoryLocation::create(['id' => 1, 'nama_factory' => 'Pabrik A', 'alamat_factory' => 'Jl. Industri', 'priority' => 1]);
    Station::create(['id' => 1, 'nama_station' => 'kayu', 'factory_location_id' => 1, 'biaya_harian' => 50000]);
    Station::create(['id' => 2, 'nama_station' => 'cat',  'factory_location_id' => 1, 'biaya_harian' => 35000]);
    Station::create(['id' => 3, 'nama_station' => 'acc',  'factory_location_id' => 1, 'biaya_harian' => 20000]);
    for ($i = 1; $i <= 6; $i++) {
        Team::create(['kode_team' => "KAYU-$i", 'station_id' => 1]);
        Team::create(['kode_team' => "CAT-$i",  'station_id' => 2]);
    }
    for ($i = 1; $i <= 3; $i++) {
        Team::create(['kode_team' => "ACC-$i", 'station_id' => 3]);
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

/* ---------------------------------------------------------------------- */
/*  Helpers                                                               */
/* ---------------------------------------------------------------------- */

/**
 * Create a production order (already in await_material so the observer does
 * not auto-schedule), its SPK, and N identical items.
 */
function mkOrder(array $a): ProductionOrder
{
    $order = ProductionOrder::create([
        'order_id'            => $a['order_id'],
        'nama_customer'       => $a['order_id'],
        'alamat_customer'     => 'Addr',
        'tanggal_order'       => Carbon::now()->subDays(2)->toDateString(),
        'status_id'           => $a['status'] ?? 'await_material',
        'is_urgent'           => $a['urgent'] ?? false,
        'production_deadline' => $a['deadline'],
    ]);

    Spk::create([
        'nomor_spk'           => 'SPK-' . $a['order_id'],
        'tanggal_terbit'      => Carbon::now()->toDateString(),
        'production_order_id' => $order->id,
        'assigned_factory'    => $a['factory'] ?? 1,
    ]);

    for ($j = 0; $j < ($a['items'] ?? 3); $j++) {
        ProductionOrderItem::create([
            'production_order_id' => $order->id,
            'product_id'          => 1,
            'panjang'             => $a['panjang'] ?? 200,
            'tinggi'              => $a['tinggi'] ?? 100,
            'quantity'            => $a['qty'] ?? 2,
        ]);
    }

    return $order;
}

/** Pretty datetime, or "—" when null. */
function fmt(?Carbon $t): string
{
    return $t ? $t->format('D d M H:i') : '—';
}

function row(array $cols, array $widths): void
{
    $out = '| ';
    foreach ($cols as $i => $c) {
        $out .= str_pad((string) $c, $widths[$i]) . ' | ';
    }
    echo rtrim($out) . "\n";
}

/* ---------------------------------------------------------------------- */
/*  SCENARIO 1 — Urgent order arrives while production is running          */
/* ---------------------------------------------------------------------- */

test('SCENARIO 1: urgent order jumps the queue without disturbing running work', function () {
    $sched = app(SchedulingService::class);

    // Existing production plan: three normal orders, 1 item each (kept simple so
    // each order's start maps to exactly one wood slot — no intra-order noise).
    $n1 = mkOrder(['order_id' => 'N1', 'deadline' => now()->addDays(40), 'items' => 1]);
    $n2 = mkOrder(['order_id' => 'N2', 'deadline' => now()->addDays(45), 'items' => 1]);
    $n3 = mkOrder(['order_id' => 'N3', 'deadline' => now()->addDays(50), 'items' => 1]);
    $sched->scheduleUnassignedItems();

    $before = [
        'N1' => $n1->fresh()->production_start,
        'N2' => $n2->fresh()->production_start,
        'N3' => $n3->fresh()->production_start,
    ];

    // Production has STARTED on N1's wood station — must never be moved.
    $running = ProductionSchedule::whereHas('orderItem', fn ($q) => $q->where('production_order_id', $n1->id))
        ->whereHas('station', fn ($q) => $q->where('nama_station', 'kayu'))
        ->orderBy('start_time')->first();
    $running->update(['status' => 'in_progress', 'actual_start' => now()]);
    $runningStartLocked = $running->start_time->copy();

    // A rush order lands and its material is confirmed on the spot.
    $urgent = mkOrder(['order_id' => 'URGENT', 'deadline' => now()->addDays(7), 'urgent' => true, 'items' => 1]);
    $sched->confirmMaterialArrival($urgent->id);

    $after = [
        'URGENT' => $urgent->fresh()->production_start,
        'N1'     => $n1->fresh()->production_start,
        'N2'     => $n2->fresh()->production_start,
        'N3'     => $n3->fresh()->production_start,
    ];

    $w = [8, 22, 22];
    echo "\n=== SCENARIO 1: Urgent order during ongoing production ===\n";
    row(['Order', 'Plan BEFORE urgent', 'Plan AFTER urgent'], $w);
    row(['------', '----------------------', '----------------------'], $w);
    row(['URGENT', '(not yet created)', fmt($after['URGENT'])], $w);
    foreach (['N1', 'N2', 'N3'] as $o) {
        row([$o, fmt($before[$o]), fmt($after[$o])], $w);
    }
    $seq = $sched->getSimulatedOrderSequence(ProductionOrder::all());
    $idToName = ProductionOrder::pluck('order_id', 'id');
    $runningAfter = ProductionSchedule::find($running->id);
    echo "Scheduled order sequence: "
        . implode(' -> ', array_map(fn ($id) => $idToName[$id], $seq)) . "\n";
    echo "N1 in-progress kayu slot  BEFORE: " . fmt($runningStartLocked) . " (in_progress)\n";
    echo "N1 in-progress kayu slot  AFTER : " . fmt($runningAfter->start_time) . " ({$runningAfter->status})\n";

    // 1. Urgent is sequenced first.
    expect($seq[0])->toBe($urgent->id);
    // 2. Urgent starts earlier than every queued normal order.
    foreach (['N1', 'N2', 'N3'] as $o) {
        expect($after['URGENT']->lt($after[$o]))->toBeTrue();
    }
    // 3. The in-progress slot was NOT touched by the re-optimisation.
    $stillRunning = ProductionSchedule::find($running->id);
    expect($stillRunning->status)->toBe('in_progress');
    expect($stillRunning->start_time->equalTo($runningStartLocked))->toBeTrue();
});

/* ---------------------------------------------------------------------- */
/*  SCENARIO 2 — Material arrives EARLY                                    */
/* ---------------------------------------------------------------------- */

test('SCENARIO 2: early material arrival pulls the order forward', function () {
    $sched = app(SchedulingService::class);

    $a = mkOrder(['order_id' => 'A-EARLY', 'deadline' => now()->addDays(40)]);
    $b = mkOrder(['order_id' => 'B-WAIT',  'deadline' => now()->addDays(42)]);
    $sched->scheduleUnassignedItems();

    $aBefore = $a->fresh()->production_start;
    $bBefore = $b->fresh()->production_start;

    // A's wood actually shows up today, weeks ahead of the assumed ETA.
    $sched->confirmMaterialArrival($a->id);

    $aAfter = $a->fresh()->production_start;
    $bAfter = $b->fresh()->production_start;

    $w = [8, 22, 22];
    echo "\n=== SCENARIO 2: Early material arrival (Order A) ===\n";
    row(['Order', 'Start BEFORE arrival', 'Start AFTER arrival'], $w);
    row(['------', '----------------------', '----------------------'], $w);
    row(['A-EARLY', fmt($aBefore), fmt($aAfter)], $w);
    row(['B-WAIT',  fmt($bBefore), fmt($bAfter)], $w);
    echo "A pulled forward by ~" . $aAfter->diffInDays($aBefore) . " day(s).\n";

    // A moved earlier; B (still awaiting) stays back; A now precedes B.
    expect($aAfter->lt($aBefore))->toBeTrue();
    expect($aAfter->lte(now()->addDays(3)))->toBeTrue();
    expect($aAfter->lt($bAfter))->toBeTrue();
});

/* ---------------------------------------------------------------------- */
/*  SCENARIO 3 — Material DELAYED, a ready order overtakes                 */
/* ---------------------------------------------------------------------- */

test('SCENARIO 3: delayed material lets a ready order overtake', function () {
    $sched = app(SchedulingService::class);
    $orderService = app(ProductionOrderService::class);

    $delayed = mkOrder(['order_id' => 'DELAYED', 'deadline' => now()->addDays(30)]);
    $ready   = mkOrder(['order_id' => 'READY',   'deadline' => now()->addDays(35)]);
    $sched->scheduleUnassignedItems();

    $delayedStartBefore = $delayed->fresh()->production_start;
    $readyStartBefore   = $ready->fresh()->production_start;
    $etaBefore          = $delayed->fresh()->material_eta;

    // Supplier informs us DELAYED's material slips ~6 weeks.
    $orderService->extendMaterialEta($delayed->id, now()->addDays(45)->toDateString());
    $etaAfter = $delayed->fresh()->material_eta;

    // Meanwhile READY's material actually arrives.
    $sched->confirmMaterialArrival($ready->id);

    $delayedStartAfter = $delayed->fresh()->production_start;
    $readyStartAfter   = $ready->fresh()->production_start;
    $delayedScheduleCount = ProductionSchedule::whereHas(
        'orderItem',
        fn ($q) => $q->where('production_order_id', $delayed->id)
    )->count();

    $w = [9, 14, 22, 22];
    echo "\n=== SCENARIO 3: Delayed material vs ready order ===\n";
    row(['Order', 'Material ETA', 'Start BEFORE', 'Start AFTER'], $w);
    row(['---------', '--------------', '----------------------', '----------------------'], $w);
    row(['DELAYED', fmt($etaBefore) . '→' . fmt($etaAfter), fmt($delayedStartBefore), fmt($delayedStartAfter)], $w);
    row(['READY',   '(arrived)',                            fmt($readyStartBefore),   fmt($readyStartAfter)], $w);
    echo "DELAYED still holds {$delayedScheduleCount} schedule rows (not dropped).\n";

    // ETA recorded; READY overtakes DELAYED; DELAYED remains validly planned.
    expect($etaAfter->toDateString())->toBe(now()->addDays(45)->toDateString());
    expect($readyStartAfter->lt($delayedStartAfter))->toBeTrue();
    expect($delayedScheduleCount)->toBeGreaterThan(0);
});

/* ---------------------------------------------------------------------- */
/*  SCENARIO 4 — Limited capacity, deadline-driven priority               */
/* ---------------------------------------------------------------------- */

test('SCENARIO 4: under heavy load Pabrik A resolves priority by tier and never overlaps', function () {
    $sched = app(SchedulingService::class);

    // Load Pabrik A past its capacity (8 orders × 3 large items = 24 items)
    // so the kayu/cat/acc teams genuinely queue. With only 3 acc teams this
    // forces real contention — exactly the situation the algorithm must resolve.
    $urgent   = mkOrder(['order_id' => 'URGENT', 'deadline' => now()->addDays(5),  'urgent' => true, 'items' => 3, 'panjang' => 250, 'tinggi' => 200]);
    $critical = mkOrder(['order_id' => 'CRIT',   'deadline' => now()->addDays(6),                    'items' => 3, 'panjang' => 250, 'tinggi' => 200]);

    $normals = [];
    foreach ([40, 45, 50, 55, 60, 70] as $k => $days) {
        $normals[] = mkOrder([
            'order_id' => 'NORM' . ($k + 1),
            'deadline' => now()->addDays($days),
            'items'    => 3, 'panjang' => 250, 'tinggi' => 200,
        ]);
    }

    $sched->scheduleUnassignedItems();

    $all = array_merge([$urgent, $critical], $normals);
    $seq = $sched->getSimulatedOrderSequence(ProductionOrder::whereIn('id', collect($all)->pluck('id'))->get());
    $pos = array_flip($seq);

    $tier = fn ($o) => $o->is_urgent
        ? 'urgent'
        : (Carbon::now()->diffInDays($o->fresh()->production_deadline, false) <= 7 ? 'critical' : 'normal');

    $w = [10, 10, 9, 22];
    echo "\n=== SCENARIO 4: 8 orders contend for Pabrik A (6 kayu / 6 cat / 3 acc) ===\n";
    row(['Order', 'Tier', 'Seq pos', 'Production start'], $w);
    row(['----------', '----------', '---------', '----------------------'], $w);
    foreach ($seq as $id) {
        $o = ProductionOrder::find($id);
        row([$o->order_id, $tier($o), (string) ($pos[$id] + 1), fmt($o->production_start)], $w);
    }

    // Tier ordering: urgent is first, critical second, normals after both.
    expect($pos[$urgent->id])->toBe(0);
    expect($pos[$critical->id])->toBe(1);
    foreach ($normals as $n) {
        expect($pos[$critical->id])->toBeLessThan($pos[$n->id]);
    }

    // Start-time ordering reflects the priority under contention.
    $start = fn ($o) => ProductionOrder::find($o->id)->production_start;
    foreach ($normals as $n) {
        expect($start($urgent)->lte($start($n)))->toBeTrue();
        expect($start($critical)->lte($start($n)))->toBeTrue();
    }

    // Correctness invariant: no team in Pabrik A is ever double-booked.
    $teamIds = Team::whereIn('station_id', [1, 2, 3])->pluck('id');
    $overlaps = 0;
    foreach ($teamIds as $teamId) {
        $slots = ProductionSchedule::where('team_id', $teamId)
            ->orderBy('start_time')->get(['start_time', 'end_time']);
        $prevEnd = null;
        foreach ($slots as $s) {
            if ($prevEnd !== null && $s->start_time->lt($prevEnd)) {
                $overlaps++;
            }
            $prevEnd = $s->end_time;
        }
    }
    echo "Team double-bookings detected on Pabrik A: {$overlaps}\n";
    expect($overlaps)->toBe(0);
});

/* ---------------------------------------------------------------------- */
/*  SCENARIO 5 — A station finishes EARLY, downstream reflows             */
/* ---------------------------------------------------------------------- */

test('SCENARIO 5: finishing kayu early pulls cat and acc forward', function () {
    $sched           = app(SchedulingService::class);
    $scheduleService = app(ProductionScheduleService::class);

    // One on-going order so its three stations are planned end-to-end.
    $order = mkOrder([
        'order_id' => 'REFLOW',
        'deadline' => now()->addDays(20),
        'items'    => 1, 'panjang' => 200, 'tinggi' => 150, 'qty' => 3,
    ]);
    $sched->confirmMaterialArrival($order->id);

    $item = $order->items()->first();
    $get  = fn ($name) => ProductionSchedule::where('production_order_item_id', $item->id)
        ->whereHas('station', fn ($q) => $q->where('nama_station', $name))->first();

    $kayu = $get('kayu');
    $cat  = $get('cat');
    $acc  = $get('acc');

    $catStartBefore = $cat->start_time->copy();
    $accStartBefore = $acc->start_time->copy();

    // The wood team finishes well ahead of plan. The engine may already have
    // auto-started kayu (its slot begins "now"), so only start it if still
    // pending, then complete it immediately to simulate the early finish.
    if ($kayu->status === 'pending') {
        $scheduleService->updateScheduleStatus($kayu->id, 'in_progress');
    }
    $scheduleService->updateScheduleStatus($kayu->id, 'completed');

    $kayu = $get('kayu');
    $cat  = $get('cat');
    $acc  = $get('acc');

    $w = [8, 24, 24];
    echo "\n=== SCENARIO 5: kayu finishes early -> downstream reflow ===\n";
    row(['Station', 'Start BEFORE early finish', 'Start AFTER early finish'], $w);
    row(['-------', '------------------------', '------------------------'], $w);
    row(['kayu', fmt($kayu->start_time) . ' (' . $kayu->status . ')', 'completed early'], $w);
    row(['cat',  fmt($catStartBefore),                                  fmt($cat->start_time)], $w);
    row(['acc',  fmt($accStartBefore),                                  fmt($acc->start_time)], $w);
    echo "cat pulled forward by ~" . $cat->start_time->diffInHours($catStartBefore) . "h, "
        . "acc by ~" . $acc->start_time->diffInHours($accStartBefore) . "h.\n";

    // Downstream chain stays intact: acc never starts before cat ends.
    expect($acc->start_time->gte($cat->end_time))->toBeTrue();
    // Both downstream stations moved earlier (or held) once kayu freed up.
    expect($cat->start_time->lte($catStartBefore))->toBeTrue();
    expect($acc->start_time->lt($accStartBefore))->toBeTrue();
});
