<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\Spk;
use App\Models\FactoryLocation;
use App\Models\Station;
use App\Models\Team;
use App\Models\Product;
use App\Services\Production\SchedulingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SchedulingAfter15Test extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_after_15_rule()
    {
        // 1. Setup factory, stations, and teams
        \App\Models\ProductionStatus::create(['id' => 'await_material', 'label' => 'Await Material', 'urutan' => 1, 'color' => 'blue']);
        
        $factory = FactoryLocation::create(['nama_factory' => 'Factory A', 'alamat_factory' => 'Test', 'priority' => 1]);
        
        $stations = ['kayu', 'cat', 'acc'];
        foreach ($stations as $nama) {
            $station = Station::create(['factory_location_id' => $factory->id, 'nama_station' => $nama, 'urutan' => 1, 'biaya_harian' => 100]);
            Team::create(['station_id' => $station->id, 'kode_team' => strtoupper($nama) . '-1', 'capacity' => 1]);
        }

        $product = Product::create(['kode_product' => 'P1', 'nama_product' => 'Product 1']);

        // We set material_eta to Monday specifically to control the timeline
        $monday = Carbon::now()->next(Carbon::MONDAY)->setTime(8, 0, 0);

        // 2. Create multiple orders with multiple items
        for ($i = 1; $i <= 2; $i++) {
            $order = ProductionOrder::create([
                'order_id' => "ORD-00$i",
                'nama_customer' => "Customer $i",
                'alamat_customer' => "Address $i",
                'tanggal_order' => now()->subDays(2),
                'status_id' => 'await_material',

                'production_deadline' => now()->addMonths(1),
                'is_urgent' => false,
            ]);

            Spk::create([
                'nomor_spk' => "SPK-00$i",
                'production_order_id' => $order->id,
                'assigned_factory' => $factory->id,
                'tanggal_terbit' => now()->toDateString(),
                'deadline' => now()->addMonths(1)->toDateString(),
            ]);

            // Add 2 items to each order
            // Make sizes large enough so duration > a few hours to trigger the 15:00 rule
            // Config default: base_production_minutes = 120, minutes_per_m2 = 60, cm2_per_m2 = 10000
            // We want an item to take around 6 hours (360 mins) total, or 3-4 hours per station, to cross 15:00
            for ($j = 1; $j <= 2; $j++) {
                ProductionOrderItem::create([
                    'production_order_id' => $order->id,
                    'product_id' => $product->id,

                    'panjang' => 200,
                    'tinggi' => 200, // 4m2 -> 4 * 60 = 240 mins + base 60 = 300 mins total.
                    // Split is 45% kayu (135 min), 45% cat (135 min), 10% acc (30 min)
                    'quantity' => 2, // Double quantity -> 8m2 -> 480 mins + 60 = 540 total -> 243 min kayu
                ]);
            }
        }

        // 3. Run Scheduling
        $service = new SchedulingService();
        $service->scheduleUnassignedItems();

        // 4. Output the result
        $schedules = \App\Models\ProductionSchedule::with(['station', 'team', 'orderItem.productionOrder'])
            ->orderBy('start_time')
            ->get();

        echo "\n\n=== SCHEDULE OUTPUT ===\n";
        echo "Rule: 08:00 - 17:00 Work Hours | If finished after 15:00 -> Next availability is Next Day 08:00\n";
        echo "--------------------------------------------------------------------------------------\n";
        foreach ($schedules as $schedule) {
            $orderId = $schedule->orderItem->productionOrder->order_id;
            $station = $schedule->station->nama_station;
            $start = Carbon::parse($schedule->start_time)->format('D, Y-m-d H:i');
            $end = Carbon::parse($schedule->end_time)->format('D, Y-m-d H:i');
            
            // Highlight if finished after 15:00
            $after15 = Carbon::parse($schedule->end_time)->format('H:i') >= '15:00' ? " [>15:00]" : "";
            
            echo sprintf("Order: %-8s | Item: %d | Station: %-4s | Start: %-20s | End: %-20s%s\n", 
                $orderId, $schedule->production_order_item_id, $station, $start, $end, $after15);
        }
        echo "======================================================================================\n\n";

        $this->assertTrue($schedules->isNotEmpty());
    }
}
