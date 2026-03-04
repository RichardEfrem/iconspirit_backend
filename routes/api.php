<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Inventory\MaterialController;

// Inventory Material Routes
Route::prefix('inventory')->group(function () {
    Route::get('material', [MaterialController::class, 'index']);
    Route::get('material/stock', [MaterialController::class, 'getStockCounts']);
    Route::get('material/{id}', [MaterialController::class, 'show']);
    Route::post('material', [MaterialController::class, 'store']);
    Route::patch('material/{id}/stock', [MaterialController::class, 'updateStock']);
});

