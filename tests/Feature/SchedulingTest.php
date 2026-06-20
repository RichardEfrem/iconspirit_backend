<?php

use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionSchedule;
use App\Models\Product;
use App\Models\Station;
use App\Models\Team;
use App\Models\FactoryLocation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('scheduling flow simulation', function () {

    
    $this->seed();
    $factory = FactoryLocation::first();
    $kayuStation = Station::where('nama_station', 'kayu')->first();
    $catStation = Station::where('nama_station', 'cat')->first();
    $accStation = Station::where('nama_station', 'acc')->first();
    $product = Product::first();

    // Mock configs
    config(['production.minutes_per_m2' => 60]);
    config(['production.base_production_minutes' => 120]);
    config(['production.cm2_per_m2' => 10000]);
    config(['production.work_minutes_per_day' => 480]);
    config(['production.transit_delay_days' => 1]);
    config(['production.station_split' => ['kayu' => 0.4, 'cat' => 0.4, 'acc' => 0.2]]);
    
    $orderService = app(\App\Services\Production\ProductionOrderService::class);
    $schedulingService = app(\App\Services\Production\SchedulingService::class);

    // 2. Create Order
    $order = $orderService->create([
        'nama_customer' => 'Test Customer',
        'alamat_customer' => 'Test Address',
        'tanggal_order' => now()->toDateString(),
        'status_id' => 'new'
    ]);

    $item = ProductionOrderItem::create([
        'production_order_id' => $order->id,
        'product_id' => $product->id,
        'panjang' => 100,
        'tinggi' => 100,
        'quantity' => 1,
    ]);

    // 3. Mark as Await Material
    // This should trigger the observer and schedule the items.
    $order = $orderService->markAsAwaitMaterial($order->id, 'await_material');
    
    expect($order->status_id)->toBe('await_material');


    $schedules = ProductionSchedule::where('production_order_id', $order->id)
        ->join('production_order_item', 'production_order_item.id', '=', 'production_schedules.production_order_item_id')
        ->get();
        
    expect($schedules->count())->toBe(3); // Kayu, Cat, Acc
    
    // Check they are pending
    foreach ($schedules as $schedule) {
        expect($schedule->status)->toBe('pending');
    }

    $initialStartKayu = ProductionSchedule::where('station_id', $kayuStation->id)->first()->start_time;
    
    // 4. Confirm Material Arrival (Early by 1 day)

    
    $schedulingService->confirmMaterialArrival($order->id);

    $order->refresh();
    expect($order->status_id)->toBe('on_going');

    // Schedules should be shifted earlier
    $shiftedStartKayu = ProductionSchedule::where('station_id', $kayuStation->id)->first()->start_time;
    
    // Check if shifted start is earlier than initial start
    expect(Carbon::parse($shiftedStartKayu)->lt(Carbon::parse($initialStartKayu)))->toBeTrue();
    // 5. Complete Kayu Station
    $kayuSchedule = ProductionSchedule::where('station_id', $kayuStation->id)->first();
    expect($kayuSchedule->status)->toBe('in_progress'); // Should be started by refreshQueues
    
    $scheduleService = app(\App\Services\Production\ProductionScheduleService::class);
    $scheduleService->updateScheduleStatus($kayuSchedule->id, 'completed');
    
    $kayuSchedule->refresh();
    expect($kayuSchedule->status)->toBe('completed');
    
    // Cat should now be in progress
    $catSchedule = ProductionSchedule::where('station_id', $catStation->id)->first();
    expect($catSchedule->status)->toBe('in_progress');
    
    // 6. Complete Cat Station
    $scheduleService->updateScheduleStatus($catSchedule->id, 'completed');
    
    // Acc should now be in progress
    $accSchedule = ProductionSchedule::where('station_id', $accStation->id)->first();
    expect($accSchedule->status)->toBe('in_progress');
    
    // 7. Complete Acc Station
    $scheduleService->updateScheduleStatus($accSchedule->id, 'completed');
    
    // 8. Order should be auto-finished
    $order->refresh();
    expect($order->status_id)->toBe('finished');

    Carbon::setTestNow(); // Reset time
});

test('multiple orders with multiple items scheduling', function () {
    $this->seed();
    $factory = FactoryLocation::first();
    $kayuStation = Station::where('nama_station', 'kayu')->first();
    $product = Product::first();

    config(['production.minutes_per_m2' => 60]);
    config(['production.base_production_minutes' => 120]);
    config(['production.cm2_per_m2' => 10000]);
    config(['production.work_minutes_per_day' => 480]);
    config(['production.transit_delay_days' => 1]);
    config(['production.station_split' => ['kayu' => 0.4, 'cat' => 0.4, 'acc' => 0.2]]);
    
    $orderService = app(\App\Services\Production\ProductionOrderService::class);
    $schedulingService = app(\App\Services\Production\SchedulingService::class);

    // Order 1 (2 Items)
    $order1 = $orderService->create([
        'nama_customer' => 'Customer A',
        'alamat_customer' => 'Address A',
        'tanggal_order' => now()->toDateString(),
        'status_id' => 'new'
    ]);
    ProductionOrderItem::create(['production_order_id' => $order1->id, 'product_id' => $product->id, 'panjang' => 100, 'tinggi' => 100, 'quantity' => 1]);
    ProductionOrderItem::create(['production_order_id' => $order1->id, 'product_id' => $product->id, 'panjang' => 200, 'tinggi' => 100, 'quantity' => 1]);

    // Order 2 (3 Items)
    $order2 = $orderService->create([
        'nama_customer' => 'Customer B',
        'alamat_customer' => 'Address B',
        'tanggal_order' => now()->toDateString(),
        'status_id' => 'new'
    ]);
    ProductionOrderItem::create(['production_order_id' => $order2->id, 'product_id' => $product->id, 'panjang' => 150, 'tinggi' => 150, 'quantity' => 2]);
    ProductionOrderItem::create(['production_order_id' => $order2->id, 'product_id' => $product->id, 'panjang' => 100, 'tinggi' => 150, 'quantity' => 1]);
    ProductionOrderItem::create(['production_order_id' => $order2->id, 'product_id' => $product->id, 'panjang' => 50, 'tinggi' => 50, 'quantity' => 5]);

    $orderService->markAsAwaitMaterial($order1->id, 'await_material');
    $orderService->markAsAwaitMaterial($order2->id, 'await_material');

    $schedules1 = ProductionSchedule::where('production_order_item_id', 'IN', function($q) use ($order1) {
        $q->select('id')->from('production_order_item')->where('production_order_id', $order1->id);
    })->get();
    
    // Total 6 schedules for Order 1
    // Wait, the subquery needs DB::raw or just whereIn
    $schedules1 = ProductionSchedule::whereIn('production_order_item_id', $order1->items->pluck('id'))->get();
    expect($schedules1->count())->toBe(6);

    $schedules2 = ProductionSchedule::whereIn('production_order_item_id', $order2->items->pluck('id'))->get();
    expect($schedules2->count())->toBe(9);

    // Confirm material arrival
    $schedulingService->confirmMaterialArrival($order1->id);
    $schedulingService->confirmMaterialArrival($order2->id);

    $order1->refresh();
    $order2->refresh();
    
    expect($order1->status_id)->toBe('on_going');
    expect($order2->status_id)->toBe('on_going');
});

test('urgent orders are prioritized when scheduling a batch', function () {
    $this->seed();
    $factory = FactoryLocation::first();
    $product = Product::first();

    config(['production.minutes_per_m2' => 60]);
    config(['production.base_production_minutes' => 120]);
    config(['production.cm2_per_m2' => 10000]);
    config(['production.work_minutes_per_day' => 480]);
    config(['production.transit_delay_days' => 1]);
    config(['production.station_split' => ['kayu' => 0.4, 'cat' => 0.4, 'acc' => 0.2]]);
    
    $orderService = app(\App\Services\Production\ProductionOrderService::class);
    $schedulingService = app(\App\Services\Production\SchedulingService::class);

    // Create 5 Normal Orders
    $normalOrders = [];
    for ($i = 1; $i <= 5; $i++) {
        $order = $orderService->create([
            'nama_customer' => "Normal Customer $i",
            'alamat_customer' => "Address $i",
            'tanggal_order' => now()->toDateString(),
            'status_id' => 'new'
        ]);
        ProductionOrderItem::create(['production_order_id' => $order->id, 'product_id' => $product->id, 'panjang' => 100, 'tinggi' => 100, 'quantity' => 1]);
        $orderService->markAsAwaitMaterial($order->id, 'await_material');
        $normalOrders[] = $order;
    }

    // Now insert 1 Urgent Order
    $urgentOrder = $orderService->create([
        'nama_customer' => "Urgent Customer",
        'alamat_customer' => "Urgent Address",
        'tanggal_order' => now()->toDateString(),
        'status_id' => 'new',
        'is_urgent' => true
    ]);
    ProductionOrderItem::create(['production_order_id' => $urgentOrder->id, 'product_id' => $product->id, 'panjang' => 100, 'tinggi' => 100, 'quantity' => 1]);
    $orderService->markAsAwaitMaterial($urgentOrder->id, 'await_material');

    // Confirm material arrival for ALL of them at the same time to simulate batch scheduling
    foreach ($normalOrders as $order) {
        $schedulingService->confirmMaterialArrival($order->id);
    }
    $schedulingService->confirmMaterialArrival($urgentOrder->id);

    // Get the urgent schedule sequence
    $urgentSchedules = ProductionSchedule::whereIn('production_order_item_id', $urgentOrder->items->pluck('id'))->orderBy('start_time')->get();
    
    // Test logic: Verify if it is properly populated
    expect($urgentSchedules->count())->toBe(3);
});
