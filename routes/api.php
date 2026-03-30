<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Inventory\MaterialController;
use App\Http\Controllers\Api\Production\FactoryLocationController;
use App\Http\Controllers\Api\Production\LocationTransitTimeController;
use App\Http\Controllers\Api\Production\ProductController;
use App\Http\Controllers\Api\Production\ProductionOrderItemController;
use App\Http\Controllers\Api\Production\ProductionOrderController;
use App\Http\Controllers\Api\Production\StationController;
use App\Http\Controllers\Api\Production\TeamController;

// Inventory Material Routes
Route::prefix('inventory')->group(function () {
    Route::get('material', [MaterialController::class, 'index']);
    Route::get('material/stock', [MaterialController::class, 'getStockCounts']);
    Route::get('material/{id}', [MaterialController::class, 'show']);
    Route::post('material', [MaterialController::class, 'store']);
    Route::patch('material/{id}/stock', [MaterialController::class, 'updateStock']);
});

// Production Routes
Route::prefix('production')->group(function () {
    Route::prefix('factory-location')->group(function () {
        Route::get('/', [FactoryLocationController::class, 'index']);
        Route::get('/{id}', [FactoryLocationController::class, 'show']);
        Route::post('/add', [FactoryLocationController::class, 'store']);
        Route::put('/{id}/update', [FactoryLocationController::class, 'update']);
        Route::delete('/{id}/delete', [FactoryLocationController::class, 'destroy']);
    });

    Route::prefix('location-transit-time')->group(function () {
        Route::get('/', [LocationTransitTimeController::class, 'index']);
        Route::get('/{id}', [LocationTransitTimeController::class, 'show']);
        Route::post('/add', [LocationTransitTimeController::class, 'store']);
        Route::put('/{id}/update', [LocationTransitTimeController::class, 'update']);
        Route::delete('/{id}/delete', [LocationTransitTimeController::class, 'destroy']);
    });

    Route::prefix('product')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/{id}', [ProductController::class, 'show']);
        Route::post('/add', [ProductController::class, 'store']);
        Route::put('/{id}/update', [ProductController::class, 'update']);
        Route::delete('/{id}/delete', [ProductController::class, 'destroy']);
    });

    Route::prefix('station')->group(function () {
        Route::get('/', [StationController::class, 'index']);
        Route::get('/{id}', [StationController::class, 'show']);
        Route::post('/add', [StationController::class, 'store']);
        Route::put('/{id}/update', [StationController::class, 'update']);
        Route::delete('/{id}/delete', [StationController::class, 'destroy']);
    });

    Route::prefix('team')->group(function () {
        Route::get('/', [TeamController::class, 'index']);
        Route::get('/{id}', [TeamController::class, 'show']);
        Route::post('/add', [TeamController::class, 'store']);
        Route::put('/{id}/update', [TeamController::class, 'update']);
        Route::delete('/{id}/delete', [TeamController::class, 'destroy']);
    });

    // Production Orders
    Route::prefix('order')->group(function () {
        Route::get('/', [ProductionOrderController::class, 'index']);          // List all orders
        Route::get('/{id}', [ProductionOrderController::class, 'show']);       // Get single order detail
        Route::post('/add', [ProductionOrderController::class, 'store']);
        Route::put('/{id}/update', [ProductionOrderController::class, 'update']);
    });

    // Production Order Items
    Route::prefix('order-item')->group(function () {
        Route::get('/', [ProductionOrderItemController::class, 'index']);             // List all items
        Route::get('/{id}', [ProductionOrderItemController::class, 'show']);          // Get single item detail
        Route::get('/order/{orderId}', [ProductionOrderItemController::class, 'getByOrder']); // Get items by Parent Order ID
        Route::post('/add', [ProductionOrderItemController::class, 'store']);
        Route::put('/{id}/update', [ProductionOrderItemController::class, 'update']);
    });
});
