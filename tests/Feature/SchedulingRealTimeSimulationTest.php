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

class SchedulingRealTimeSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_time_simulation()
    {
        \App\Models\ProductionStatus::create(['id' => 'await_material', 'label' => 'Await Material', 'urutan' => 1, 'color' => 'blue']);

        $factory = FactoryLocation::create(['nama_factory' => 'Factory A', 'alamat_factory' => 'Main Location', 'priority' => 1]);
        
        $stationsConfig = [
            'kayu' => 6,
            'cat'  => 6,
            'acc'  => 3,
        ];

        foreach ($stationsConfig as $nama => $teamCount) {
            $station = Station::create(['factory_location_id' => $factory->id, 'nama_station' => $nama, 'urutan' => 1, 'biaya_harian' => 100]);
            for ($t = 1; $t <= $teamCount; $t++) {
                Team::create(['station_id' => $station->id, 'kode_team' => strtoupper($nama) . "-T$t", 'capacity' => 1]);
            }
        }

        $product = Product::create(['kode_product' => 'P1', 'nama_product' => 'Custom Wood Product']);

        $monday = Carbon::now()->next(Carbon::MONDAY)->setTime(8, 0, 0);

        // 5 Orders, 6 Items each
        for ($i = 1; $i <= 5; $i++) {
            $order = ProductionOrder::create([
                'order_id' => "ORD-2026-0$i",
                'nama_customer' => "Corporate Client $i",
                'alamat_customer' => "Business District $i",
                'tanggal_order' => now()->subDays(5),
                'status_id' => 'await_material',

                'production_deadline' => now()->addMonths(2),
                'is_urgent' => $i === 1, // Make the first one urgent
            ]);

            Spk::create([
                'nomor_spk' => "SPK-2026-0$i",
                'production_order_id' => $order->id,
                'assigned_factory' => $factory->id,
                'tanggal_terbit' => now()->toDateString(),
                'deadline' => now()->addMonths(2)->toDateString(),
            ]);

            for ($j = 1; $j <= 6; $j++) {
                // Variations in size
                $panjang = 150 + ($j * 10);
                $tinggi = 200;
                
                ProductionOrderItem::create([
                    'production_order_id' => $order->id,
                    'product_id' => $product->id,

                    'panjang' => $panjang,
                    'tinggi' => $tinggi,
                    'quantity' => rand(1, 3), // 1 to 3 items per type
                ]);
            }
        }

        $service = new SchedulingService();
        $service->scheduleUnassignedItems();

        $schedules = \App\Models\ProductionSchedule::with(['station', 'team', 'orderItem.productionOrder'])
            ->orderBy('start_time')
            ->get();

        $this->assertTrue($schedules->isNotEmpty());

        $output = "# Production Scheduling Simulation Report\n\n";
        $output .= "## Simulation Constraints\n";
        $output .= "- **Workload:** 5 Orders, 6 Items per order (Total: 30 unique items)\n";
        $output .= "- **Workforce:** Kayu (6 Teams), Cat (6 Teams), Acc (3 Teams)\n";
        $output .= "- **Working Hours:** 08:00 to 17:00 (Mon-Fri)\n";
        $output .= "- **Cutoff Rule:** Any task finishing > 15:00 shifts the team's and item's next availability to the next day at 08:00\n\n";

        // Group by Team for Timeline visualization
        $schedulesByTeam = $schedules->groupBy(fn($s) => $s->team->kode_team)->sortKeys();

        $output .= "## Timeline by Team\n\n";

        foreach ($schedulesByTeam as $teamCode => $teamSchedules) {
            $output .= "### Team: $teamCode\n";
            $output .= "```text\n";
            foreach ($teamSchedules as $schedule) {
                $orderId = $schedule->orderItem->productionOrder->order_id;
                $start = Carbon::parse($schedule->start_time)->format('D, M d, H:i');
                $end = Carbon::parse($schedule->end_time)->format('D, M d, H:i');
                
                // Real elapsed duration (including weekends)
                $duration = Carbon::parse($schedule->start_time)->diffInMinutes(Carbon::parse($schedule->end_time));
                $hours = floor($duration / 60);
                $mins = $duration % 60;
                
                $after15 = Carbon::parse($schedule->end_time)->format('H:i') >= '15:00' ? " [CUTOFF: >15:00]" : "";
                
                $output .= sprintf("=> %-13s | Item: %-2d | %s to %s (%dh %dm)%s\n", 
                    $orderId, $schedule->production_order_item_id, $start, $end, $hours, $mins, $after15);
            }
            $output .= "```\n\n";
        }

        file_put_contents(storage_path('logs/simulation_result.md'), $output);
    }
}
