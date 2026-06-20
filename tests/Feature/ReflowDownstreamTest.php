<?php

use App\Models\ProductionOrderItem;
use App\Models\ProductionSchedule;
use App\Models\Product;
use App\Models\Station;
use App\Services\Production\ProductionOrderService;
use App\Services\Production\SchedulingService;
use App\Services\Production\ProductionScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('finishing kayu early re-chains cat AND acc to follow', function () {
    $this->seed();
    Carbon::setTestNow(Carbon::parse('2026-05-29 11:15:00')); // Friday

    config(['production.station_split' => ['kayu' => 0.4, 'cat' => 0.4, 'acc' => 0.2]]);

    $product = Product::first();
    $orderService = app(ProductionOrderService::class);
    $scheduleService = app(ProductionScheduleService::class);

    $order = $orderService->create([
        'nama_customer' => 'Test Reflow',
        'alamat_customer' => 'Addr',
        'nomor_telp' => '0812',
        'tanggal_order' => now()->toDateString(),
        'status_id' => 'new',
        'is_urgent' => false,
    ]);
    ProductionOrderItem::create([
        'production_order_id' => $order->id,
        'product_id' => $product->id,
        'panjang' => 200, 'tinggi' => 150, 'quantity' => 3,
    ]);

    $orderService->markAsAwaitMaterial($order->id, 'await_material');
    app(SchedulingService::class)->confirmMaterialArrival($order->id);

    $item = $order->items()->first();
    $get = fn($name) => ProductionSchedule::where('production_order_item_id', $item->id)
        ->whereHas('station', fn($q) => $q->where('nama_station', $name))->first();

    $kayu = $get('kayu'); $cat = $get('cat'); $acc = $get('acc');
    echo "\n-- BEFORE early finish --\n";
    echo "kayu: {$kayu->start_time->format('D M d H:i')} -> {$kayu->end_time->format('D M d H:i')} ({$kayu->status})\n";
    echo "cat : {$cat->start_time->format('D M d H:i')} -> {$cat->end_time->format('D M d H:i')} ({$cat->status})\n";
    echo "acc : {$acc->start_time->format('D M d H:i')} -> {$acc->end_time->format('D M d H:i')} ({$acc->status})\n";

    $catStartBefore = $cat->start_time->copy();
    $accStartBefore = $acc->start_time->copy();

    // Start + finish kayu quickly
    $scheduleService->updateScheduleStatus($kayu->id, 'in_progress');
    $scheduleService->updateScheduleStatus($kayu->id, 'completed');

    $kayu = $get('kayu'); $cat = $get('cat'); $acc = $get('acc');
    echo "\n-- AFTER kayu completed early --\n";
    echo "kayu: {$kayu->start_time->format('D M d H:i')} -> {$kayu->end_time->format('D M d H:i')} ({$kayu->status})\n";
    echo "cat : {$cat->start_time->format('D M d H:i')} -> {$cat->end_time->format('D M d H:i')} ({$cat->status})\n";
    echo "acc : {$acc->start_time->format('D M d H:i')} -> {$acc->end_time->format('D M d H:i')} ({$acc->status})\n";

    // acc must start at/after cat ends (chain intact), and should have moved earlier than before
    expect($acc->start_time->gte($cat->end_time))->toBeTrue();
    expect($acc->start_time->lt($accStartBefore))->toBeTrue();

    Carbon::setTestNow();
});
