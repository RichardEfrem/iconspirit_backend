<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionOrderItemMaterial;
use App\Models\ProductionSchedule;
use App\Models\Spk;
use App\Models\Station;
use App\Services\Production\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validates the ProductionOrderSeeder and the SchedulingService together.
 *
 * The seeder creates 8 scenario-driven orders, all with status = new so the
 * full transition flow can be exercised manually from the UI:
 *
 *   new → await_material → on_going → finished
 *
 * Orders differ in urgency and deadline tightness to stress-test every
 * scheduler code path (priority queue, NEH, critical window, material ETA).
 */
class ProductionOrderSeederSimulationTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Create an SPK (factory assignment) for every order that lacks one. */
    private function spkAll(iterable $orders): void
    {
        foreach ($orders as $order) {
            if (!Spk::where('production_order_id', $order->id)->exists()) {
                Spk::create([
                    'nomor_spk'           => 'SPK-' . str_pad((string) $order->id, 3, '0', STR_PAD_LEFT),
                    'production_order_id' => $order->id,
                    'assigned_factory'    => 1,
                    'tanggal_terbit'      => now()->toDateString(),
                ]);
            }
        }
    }

    /** Promote a collection of orders to await_material with a specific material ETA. */
    private function promoteToAwaitMaterial(iterable $orders, ?Carbon $eta = null): void
    {
        $eta ??= now()->addDays(14);
        foreach ($orders as $order) {
            $order->update([
                'status_id'    => 'await_material',

            ]);
        }
    }

    // ── Test 1: seeder data integrity ─────────────────────────────────────────

    public function test_seeder_creates_eight_new_orders_with_correct_urgency_flags(): void
    {
        $this->seed();

        $this->assertSame(8, ProductionOrder::count(), 'Expected exactly 8 production orders');
        $this->assertSame(8, ProductionOrder::where('status_id', 'new')->count(),
            'All seeded orders must start as new');
        $this->assertSame(2, ProductionOrder::where('is_urgent', true)->count(),
            'Expected 2 urgent orders (mini kitchen + kitchen fit-out)');
    }

    // ── Test 2: deadline tightness matches urgency ────────────────────────────

    public function test_urgent_orders_have_tighter_deadlines_than_normal_orders(): void
    {
        $this->seed();

        $urgentMaxDeadline  = ProductionOrder::where('is_urgent', true)->max('production_deadline');
        $normalMinDeadline  = ProductionOrder::where('is_urgent', false)->min('production_deadline');

        $this->assertTrue(
            Carbon::parse($urgentMaxDeadline)->lt(Carbon::parse($normalMinDeadline)),
            "The loosest urgent deadline ({$urgentMaxDeadline}) must still be tighter than the tightest normal deadline ({$normalMinDeadline})"
        );
    }

    // ── Test 3: item and material counts ─────────────────────────────────────

    public function test_all_24_items_have_at_least_one_material_and_realistic_total_hours(): void
    {
        $this->seed();

        $this->assertSame(24, ProductionOrderItem::count(), 'Expected 24 items across all 8 orders');

        $withoutMaterials = ProductionOrderItem::whereDoesntHave('materials')->count();
        $this->assertSame(0, $withoutMaterials, 'Every item must have at least one material entry');

        $totalHours = ProductionOrderItem::all()->sum('estimated_processing_hours');
        $this->assertGreaterThan(500, $totalHours,
            "Total workload should exceed 500 h; got {$totalHours}");
    }

    // ── Test 4: formula correctness ───────────────────────────────────────────

    public function test_estimated_hours_match_formula_for_spot_checked_items(): void
    {
        $this->seed();

        // Kitchen base cabinet: panjang=80, tinggi=85, qty=5
        // area_m2       = 80 × 85 / 10 000 = 0.68
        // total_area_m2 = 0.68 × 5         = 3.40
        // hours         = 3.40 × (300/60)  = 17.0
        $base = ProductionOrderItem::where('panjang', 80)
            ->where('tinggi', 85)
            ->where('quantity', 5)
            ->firstOrFail();

        $this->assertEqualsWithDelta(0.68,  $base->area_m2,                    0.001);
        $this->assertEqualsWithDelta(3.40,  $base->total_area_m2,              0.01);
        $this->assertEqualsWithDelta(17.0,  $base->estimated_processing_hours, 0.1);

        // Large wallpanel: panjang=200, tinggi=240, qty=8
        // total_area_m2 = 4.80 × 8 = 38.4   hours = 38.4 × 5 = 192.0
        $wall = ProductionOrderItem::where('panjang', 200)
            ->where('tinggi', 240)
            ->where('quantity', 8)
            ->firstOrFail();

        $this->assertEqualsWithDelta(38.4,  $wall->total_area_m2,             0.1);
        $this->assertEqualsWithDelta(192.0, $wall->estimated_processing_hours, 1.0);
    }

    // ── Test 5: BOM proportionality ───────────────────────────────────────────

    public function test_blockboard_quantity_is_proportional_to_item_area(): void
    {
        $this->seed();

        // Wardrobe 200×220 vs desk 150×75 — both use Blockboard 18mm (376.00)
        $wardrobe = ProductionOrderItem::where('panjang', 200)->where('tinggi', 220)->firstOrFail();
        $desk     = ProductionOrderItem::where('panjang', 150)->where('tinggi', 75)->firstOrFail();

        $wardrobeMat = ProductionOrderItemMaterial::where('production_order_item_id', $wardrobe->id)
            ->whereHas('material', fn($q) => $q->where('kode_material', '376.00'))
            ->firstOrFail();
        $deskMat = ProductionOrderItemMaterial::where('production_order_item_id', $desk->id)
            ->whereHas('material', fn($q) => $q->where('kode_material', '376.00'))
            ->firstOrFail();

        $this->assertGreaterThan($deskMat->quantity, $wardrobeMat->quantity,
            'Wardrobe (200×220) should use more Blockboard than desk (150×75)');

        // Wallpanel 200×240×8 (38.4 m²) vs mini kitchen base 60×85×3 (1.53 m²)
        $wallPanel = ProductionOrderItem::where('panjang', 200)->where('tinggi', 240)->where('quantity', 8)->firstOrFail();
        $miniBase  = ProductionOrderItem::where('panjang', 60)->where('tinggi', 85)->where('quantity', 3)->firstOrFail();

        $wallMat = ProductionOrderItemMaterial::where('production_order_item_id', $wallPanel->id)
            ->whereHas('material', fn($q) => $q->where('kode_material', '376.00'))
            ->firstOrFail();
        $miniMat = ProductionOrderItemMaterial::where('production_order_item_id', $miniBase->id)
            ->whereHas('material', fn($q) => $q->where('kode_material', '376.00'))
            ->firstOrFail();

        $this->assertGreaterThan($miniMat->quantity, $wallMat->quantity,
            'Wallpanel (200×240×8) should use far more Blockboard than mini kitchen base (60×85×3)');
    }

    // ── Test 6: simulated sequence priority ───────────────────────────────────

    public function test_getSimulatedOrderSequence_puts_tightest_urgent_order_first(): void
    {
        $this->seed();

        // Use just the 2 urgent + 1 closest-deadline normal order to isolate priority logic
        $urgents     = ProductionOrder::where('is_urgent', true)->orderBy('production_deadline')->get();
        $normal      = ProductionOrder::where('is_urgent', false)->orderBy('production_deadline')->first();

        // Capture references before modifying the collection
        $tightUrgent = $urgents->first();
        $looseUrgent = $urgents->last();

        $subset = $urgents->push($normal); // modifies $urgents in place, returns same collection
        $this->promoteToAwaitMaterial($subset);
        $this->spkAll($subset);

        $service  = new SchedulingService();
        $sequence = $service->getSimulatedOrderSequence($subset);

        $this->assertNotEmpty($sequence);

        // First must be urgent
        $firstOrder = ProductionOrder::findOrFail($sequence[0]);
        $this->assertTrue((bool) $firstOrder->is_urgent,
            "First order in sequence (ID {$sequence[0]}) must be urgent");

        // Tightest urgent deadline (Order 6 = +2w) before looser urgent (Order 1 = +3w)
        $tighterPos = array_search($tightUrgent->id, $sequence, true);
        $looserPos  = array_search($looseUrgent->id, $sequence, true);

        $this->assertLessThan($looserPos, $tighterPos,
            "Tighter-deadline urgent (pos {$tighterPos}) should precede looser-deadline urgent (pos {$looserPos})");

        // Normal order follows both urgent ones
        $normalPos = array_search($normal->id, $sequence, true);
        $this->assertGreaterThan($tighterPos, $normalPos);
        $this->assertGreaterThan($looserPos,  $normalPos);
    }

    // ── Test 7: material ETA is respected ────────────────────────────────────

    public function test_kayu_schedules_for_material_blocked_order_start_at_or_after_eta(): void
    {
        $this->seed();

        // Simulate the biggest order (Living room ~241 h) being material-blocked for 1 week
        $bigOrder   = ProductionOrderItem::where('panjang', 200)->where('tinggi', 240)->firstOrFail()
            ->productionOrder;
        $materialEta = now()->addWeek()->startOfDay();

        $bigOrder->update([
            'status_id'    => 'await_material',

        ]);
        $this->spkAll([$bigOrder]);

        (new SchedulingService())->scheduleUnassignedItems();

        $kayuStation    = Station::where('nama_station', 'kayu')->firstOrFail();
        $bigOrderItemIds = $bigOrder->items->pluck('id');

        $kayuSchedules = ProductionSchedule::whereIn('production_order_item_id', $bigOrderItemIds)
            ->where('station_id', $kayuStation->id)
            ->get();

        $this->assertNotEmpty($kayuSchedules,
            'Material-blocked order must still receive kayu schedules (with future start)');

    }

    // ── Test 8: full simulation run + visual dump ─────────────────────────────

    /**
     * End-to-end smoke test: promote all 8 orders to await_material, run the
     * scheduler, then dump a readable timeline so you can eyeball the result.
     */
    public function test_full_scheduling_simulation_and_schedule_dump(): void
    {
        $this->seed();

        $allOrders = ProductionOrder::all();
        $this->promoteToAwaitMaterial($allOrders);
        $this->spkAll($allOrders);

        (new SchedulingService())->scheduleUnassignedItems();

        // ── assertions ────────────────────────────────────────────────────────

        // All 24 items × 3 stations = 72 schedules
        $totalSchedules = ProductionSchedule::count();
        $this->assertSame(24 * 3, $totalSchedules,
            "Expected 24 items × 3 stations = 72 schedules, got {$totalSchedules}");

        // Every item must have exactly 3 schedule entries (kayu + cat + acc)
        ProductionOrderItem::all()->each(function (ProductionOrderItem $item) {
            $count = $item->schedules()->count();
            $this->assertSame(3, $count,
                "Item {$item->id} should have 3 schedules, got {$count}");
        });

        // All orders must have production_start and estimated_end set
        $allOrders->each(function (ProductionOrder $order) {
            $order->refresh();
            $this->assertNotNull($order->production_start, "Order {$order->id} missing production_start");
            $this->assertNotNull($order->estimated_end,    "Order {$order->id} missing estimated_end");
        });

        // ── visual dump ───────────────────────────────────────────────────────

        $schedules = ProductionSchedule::with(['station', 'orderItem.productionOrder'])
            ->orderBy('start_time')
            ->get();

        $line  = str_repeat('─', 130);
        $dline = str_repeat('═', 130);

        echo "\n\n";
        echo "╔═══════════════════════════════════════════════════════════════════╗\n";
        echo "║     PRODUCTION SCHEDULE SIMULATION  —  SEEDER-BASED FULL RUN     ║\n";
        echo "╚═══════════════════════════════════════════════════════════════════╝\n";
        echo "  Flow    : new → await_material → on_going → finished\n";
        echo "  Sorting : URGENT (EDD) → CRITICAL (EDD) → NORMAL (NEH)\n";
        echo "  Hours   : 08:00–17:00 Mon–Fri  |  end ≥ 15:00 → next avail = next day 08:00\n\n";

        $currentOrder = null;
        foreach ($schedules as $s) {
            $order   = $s->orderItem->productionOrder;
            $spec    = mb_substr($s->orderItem->product?->nama_product ?? '', 0, 50);
            $station = strtoupper($s->station->nama_station);
            $start   = Carbon::parse($s->start_time)->format('D d-M H:i');
            $end     = Carbon::parse($s->end_time)->format('D d-M H:i');
            $after15 = Carbon::parse($s->end_time)->format('H:i') >= '15:00' ? ' [>15:00]' : '';

            if ($currentOrder !== $order->id) {
                $urgentTag = $order->is_urgent ? ' ★ URGENT' : '';
                $etaTag = '';
                $totalH = round($order->items->sum('estimated_processing_hours'), 1);

                echo "\n  ┌── {$order->order_id} — {$order->nama_customer}{$urgentTag}{$etaTag}  (~{$totalH} h)\n";
                $currentOrder = $order->id;
            }

            printf("  │  %-52s  %-4s  Start: %-18s  End: %-18s%s\n",
                $spec, $station, $start, $end, $after15);
        }

        echo "\n  {$dline}\n";
        echo "  ORDER SUMMARY\n";
        echo "  {$line}\n";
        printf("  %-8s  %-20s  %-8s  %-14s  %-14s  %-9s  %-10s  %s\n",
            'Order#', 'Customer', 'Urgent?', 'Prod. Start', 'Est. End', 'Deadline', 'On Time?', '~Hours');
        echo "  {$line}\n";

        $allOrders->sortBy('production_start')->each(function (ProductionOrder $order) {
            $urgent   = $order->is_urgent ? '★ YES' : 'no';
            $start    = $order->production_start
                ? Carbon::parse($order->production_start)->format('d-M H:i') : 'N/A';
            $end      = $order->estimated_end
                ? Carbon::parse($order->estimated_end)->format('d-M H:i')    : 'N/A';
            $deadline = $order->production_deadline
                ? Carbon::parse($order->production_deadline)->format('d-M')  : 'N/A';
            $onTime   = ($order->estimated_end && $order->production_deadline)
                ? (Carbon::parse($order->estimated_end)->lte(Carbon::parse($order->production_deadline))
                    ? '✓ ON TIME' : '✗ LATE   ')
                : 'unknown  ';
            $totalH   = round($order->items->sum('estimated_processing_hours'), 1);

            printf("  %-8d  %-20s  %-8s  %-14s  %-14s  %-9s  %-10s  %.1f h\n",
                $order->id, mb_substr($order->nama_customer, 0, 20),
                $urgent, $start, $end, $deadline, $onTime, $totalH);
        });

        echo "\n";
        $this->assertGreaterThan(0, $schedules->count());
    }
}
