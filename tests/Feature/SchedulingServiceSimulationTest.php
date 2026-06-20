<?php

namespace Tests\Feature;

use App\Models\FactoryLocation;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionSchedule;
use App\Models\ProductionStatus;
use App\Models\Spk;
use App\Models\Station;
use App\Models\Team;
use App\Services\Production\SchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed essential reference data

    // 1. Statuses
    ProductionStatus::insert([
        ['id' => 'new', 'label' => 'New', 'color' => 'blue'],
        ['id' => 'await_material', 'label' => 'Await Material', 'color' => 'orange'],
    ]);

    // 2. Factory Location
    $factory = FactoryLocation::create([
        'id' => 1,
        'nama_factory' => 'Pabrik A',
        'alamat_factory' => 'Alamat A',
        'priority' => 1
    ]);

    // 3. Product
    Product::create([
        'id' => 1,
        'kode_product' => 'PRD-01',
        'nama_product' => 'Standard Door'
    ]);

    // 4. Stations
    $kayuStation = Station::create(['id' => 1, 'nama_station' => 'kayu', 'factory_location_id' => 1, 'biaya_harian' => 50000]);
    $catStation = Station::create(['id' => 2, 'nama_station' => 'cat', 'factory_location_id' => 1, 'biaya_harian' => 35000]);
    $accStation = Station::create(['id' => 3, 'nama_station' => 'acc', 'factory_location_id' => 1, 'biaya_harian' => 20000]);

    // 5. Teams (Provide at least a few per station so NEH simulation works smoothly)
    for ($i = 1; $i <= 6; $i++) {
        Team::create(['id' => 100 + $i, 'kode_team' => "KAYU-$i", 'station_id' => 1]);
        Team::create(['id' => 200 + $i, 'kode_team' => "CAT-$i", 'station_id' => 2]);
    }
    for ($i = 1; $i <= 3; $i++) {
        Team::create(['id' => 300 + $i, 'kode_team' => "ACC-$i", 'station_id' => 3]);
    }
});

it('simulates production order scheduling completely', function () {
    $service = new SchedulingService();

    // 1. Create 5 different ProductionOrders with 6 ProductionOrderItems each.
    for ($i = 1; $i <= 5; $i++) {
        $order = new ProductionOrder();
        $order->id = $i;
        $order->order_id = "ORD-00$i";
        $order->nama_customer = "Customer $i";
        $order->alamat_customer = "Alamat $i";
        $order->tanggal_order = Carbon::now()->subDays(2);
        $order->status_id = 'await_material'; // Must be await_material for the service
        $order->production_deadline = Carbon::now()->addDays($i * 2); 

        $order->save();

        // Need an SPK for factory assignment in service
        Spk::create([
            'nomor_spk' => "SPK-00$i",
            'production_order_id' => $order->id,
            'assigned_factory' => 1,
        ]);

        for ($j = 1; $j <= 6; $j++) {
            ProductionOrderItem::create([
                'production_order_id' => $order->id,
                'product_id' => 1,
                'panjang' => 200, // Provides area
                'tinggi' => 100,
                'quantity' => 2,
            ]);
        }
    }

    // Verify initially unscheduled
    expect(ProductionSchedule::count())->toBe(0);

    // 2. Schedule them and ensure deadlines/starts are correctly set.
    $service->scheduleUnassignedItems();

    // Verify all 5 * 6 items = 30 items got scheduled
    // We expect each item to have 3 schedules (kayu, cat, acc) -> 30 * 3 = 90
    expect(ProductionSchedule::count())->toBe(90);

    // Ensure production_start and estimated_end are populated
    foreach (ProductionOrder::all() as $order) {
        expect($order->production_start)->not->toBeNull();
        expect($order->estimated_end)->not->toBeNull();
        expect($order->production_start->lt($order->estimated_end))->toBeTrue();
    }

    // 3. Simulate an urgent order insertion (with high priority or urgent flag)
    $urgentOrder = new ProductionOrder();
    $urgentOrder->id = 99;
    $urgentOrder->alamat_customer = "VIP Alamat";
    $urgentOrder->tanggal_order = Carbon::now()->subDays(1);
    $urgentOrder->order_id = "ORD-URGENT";
    $urgentOrder->nama_customer = "VIP Customer";
    $urgentOrder->status_id = 'await_material';
    $urgentOrder->production_deadline = Carbon::now()->addDay();

    $urgentOrder->is_urgent = true; // Urgent!
    $urgentOrder->save();

    Spk::create([
        'nomor_spk' => "SPK-URGENT",
        'production_order_id' => $urgentOrder->id,
        'assigned_factory' => 1,
    ]);

    for ($j = 1; $j <= 6; $j++) {
        ProductionOrderItem::create([
            'production_order_id' => $urgentOrder->id,
            'product_id' => 1,
            'panjang' => 200,
            'tinggi' => 100,
            'quantity' => 1,
        ]);
    }

    // Run scheduling again to incorporate urgent order
    $service->scheduleUnassignedItems();

    // Verification that new items were scheduled correctly. (30 + 6)*3 = 108
    expect(ProductionSchedule::count())->toBe(108);
    
    // Refresh urgent order to verify scheduling logic worked
    $urgentOrder->refresh();
    expect($urgentOrder->production_start)->not->toBeNull();
    
    // Simulate finding the simulated sequence to verify the urgent item is prioritized
    // Using `getSimulatedOrderSequence` with all orders
    $allOrders = ProductionOrder::all();
    $sequence = $service->getSimulatedOrderSequence($allOrders);
    
    // It should place urgent order IDs at the beginning
    expect($sequence[0])->toBe($urgentOrder->id);

    // 4. Mark all as finished
    ProductionSchedule::query()->update([
        'status' => 'completed',
        'actual_end' => Carbon::now()
    ]);
    
    // This updates the status on orders directly
    ProductionOrder::query()->update(['status_id' => 'finished']);

    expect(ProductionOrder::where('status_id', 'finished')->count())->toBe(6);
});
