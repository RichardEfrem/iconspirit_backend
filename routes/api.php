<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\Inventory\MaterialController;
use App\Http\Controllers\Api\Production\FactoryLocationController;
use App\Http\Controllers\Api\Production\ProductionOrderItemImageController;
use App\Http\Controllers\Api\Production\ProductionOrderItemMaterialController;
use App\Http\Controllers\Api\Production\ProductionOrderItemSectionController;
use App\Http\Controllers\Api\Production\ProductController;
use App\Http\Controllers\Api\Production\ProductionOrderItemController;
use App\Http\Controllers\Api\Production\ProductionOrderController;
use App\Http\Controllers\Api\Production\ProductionScheduleController;
use App\Http\Controllers\Api\Production\SpkController;
use App\Http\Controllers\Api\Production\StationController;
use App\Http\Controllers\Api\Production\TeamController;
use App\Http\Controllers\Api\Production\CustomerController;

// Auth Routes (public)
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:100,1'); // Max 5 login attempts per minute per IP
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware(['auth:sanctum', 'active']);
});

// Role aliases used below:
//   admin     - full access including user management
//   operator  - production + report (write); inventory read-only
//   inventory - inventory CRUD only
//   owner     - read-only on everything
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // Dashboard (inventory has no access — material-focused role)
    Route::middleware('role:admin,operator,owner')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);
    });

    // User Management (admin write, owner read-only)
    Route::prefix('users')->group(function () {
        Route::middleware('role:admin,owner')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::get('/{id}', [UserController::class, 'show']);
        });
        Route::middleware('role:admin')->group(function () {
            Route::post('/', [UserController::class, 'store']);
            Route::put('/{id}', [UserController::class, 'update']);
            Route::patch('/{id}/status', [UserController::class, 'updateStatus']);
            Route::delete('/{id}', [UserController::class, 'destroy']);
        });
    });

    // Inventory Material Routes
    Route::prefix('inventory')->group(function () {
        // Read: admin, operator, inventory, owner
        Route::middleware('role:admin,operator,inventory,owner')->group(function () {
            Route::get('material', [MaterialController::class, 'index']);
            Route::get('material/stock', [MaterialController::class, 'getStockCounts']);
            Route::get('material/{id}', [MaterialController::class, 'show']);
        });
        // Write: admin, inventory
        Route::middleware('role:admin,inventory')->group(function () {
            Route::post('material', [MaterialController::class, 'store']);
            Route::patch('material/{id}/stock', [MaterialController::class, 'updateStock']);
        });
    });

    // Production Routes
    Route::prefix('production')->group(function () {

        // Master data — Factory Location
        Route::prefix('factory-location')->group(function () {
            Route::middleware('role:admin,operator,owner')->group(function () {
                Route::get('/', [FactoryLocationController::class, 'index']);
                Route::get('/{id}', [FactoryLocationController::class, 'show']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [FactoryLocationController::class, 'store']);
                Route::put('/{id}/update', [FactoryLocationController::class, 'update']);
                Route::delete('/{id}/delete', [FactoryLocationController::class, 'destroy']);
            });
        });

        // Master data — Product
        Route::prefix('product')->group(function () {
            Route::middleware('role:admin,operator,owner')->group(function () {
                Route::get('/', [ProductController::class, 'index']);
                Route::get('/{id}', [ProductController::class, 'show']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [ProductController::class, 'store']);
                Route::put('/{id}/update', [ProductController::class, 'update']);
                Route::delete('/{id}/delete', [ProductController::class, 'destroy']);
            });
        });

        // Master data — Customer
        Route::prefix('customer')->group(function () {
            Route::middleware('role:admin,operator,owner')->group(function () {
                Route::get('/', [CustomerController::class, 'index']);
                Route::get('/{id}', [CustomerController::class, 'show']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [CustomerController::class, 'store']);
                Route::put('/{id}/update', [CustomerController::class, 'update']);
                Route::delete('/{id}/delete', [CustomerController::class, 'destroy']);
            });
        });

        // Master data — Station
        Route::prefix('station')->group(function () {
            Route::middleware('role:admin,operator,owner')->group(function () {
                Route::get('/', [StationController::class, 'index']);
                Route::get('/{id}', [StationController::class, 'show']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [StationController::class, 'store']);
                Route::put('/{id}/update', [StationController::class, 'update']);
                Route::delete('/{id}/delete', [StationController::class, 'destroy']);
            });
        });

        // Master data — Team
        Route::prefix('team')->group(function () {
            Route::middleware('role:admin,operator,owner')->group(function () {
                Route::get('/', [TeamController::class, 'index']);
                Route::get('/{id}', [TeamController::class, 'show']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [TeamController::class, 'store']);
                Route::put('/{id}/update', [TeamController::class, 'update']);
                Route::delete('/{id}/delete', [TeamController::class, 'destroy']);
            });
        });

        // Production Orders
        Route::prefix('order')->group(function () {
            // inventory needs read access to fulfil material requests (Permintaan Material)
            Route::middleware('role:admin,operator,owner,inventory')->group(function () {
                Route::get('/', [ProductionOrderController::class, 'index']);
                Route::get('/overdue-material', [ProductionOrderController::class, 'getOverdueMaterial']);
                Route::get('/{id}', [ProductionOrderController::class, 'show']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [ProductionOrderController::class, 'store']);
                Route::post('/run-scheduling', [ProductionOrderController::class, 'runScheduling']);
                Route::patch('/batch-confirm-material', [ProductionOrderController::class, 'confirmMaterialArrivalBatch']);
                Route::put('/{id}/update', [ProductionOrderController::class, 'update']);
                Route::patch('/{id}/await-material', [ProductionOrderController::class, 'markAsAwaitMaterial']);
                Route::patch('/{id}/confirm-material-arrival', [ProductionOrderController::class, 'confirmMaterialArrival']);
                Route::patch('/{id}/extend-material-eta', [ProductionOrderController::class, 'extendMaterialEta']);
                Route::delete('/{id}/delete', [ProductionOrderController::class, 'destroy']);
            });
        });

        // Production Order Items
        Route::prefix('order-item')->group(function () {
            Route::middleware('role:admin,operator,owner,inventory')->group(function () {
                Route::get('/', [ProductionOrderItemController::class, 'index']);
                Route::get('/{id}', [ProductionOrderItemController::class, 'show']);
                Route::get('/order/{orderId}', [ProductionOrderItemController::class, 'getByOrder']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [ProductionOrderItemController::class, 'store']);
                Route::put('/{id}/update', [ProductionOrderItemController::class, 'update']);
                Route::delete('/{id}/delete', [ProductionOrderItemController::class, 'destroy']);
            });
        });

        // Production Order Item Sections
        Route::prefix('order-item-section')->group(function () {
            Route::middleware('role:admin,operator,owner,inventory')->group(function () {
                Route::get('/', [ProductionOrderItemSectionController::class, 'index']);
                Route::get('/{id}', [ProductionOrderItemSectionController::class, 'show']);
                Route::get('/order/{orderId}', [ProductionOrderItemSectionController::class, 'getByOrder']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [ProductionOrderItemSectionController::class, 'store']);
                Route::put('/{id}/update', [ProductionOrderItemSectionController::class, 'update']);
                Route::delete('/{id}/delete', [ProductionOrderItemSectionController::class, 'destroy']);
            });
        });

        // Production Order Item Materials
        Route::prefix('order-item-material')->group(function () {
            Route::middleware('role:admin,operator,owner,inventory')->group(function () {
                Route::get('/', [ProductionOrderItemMaterialController::class, 'index']);
                Route::get('/{id}', [ProductionOrderItemMaterialController::class, 'show']);
                Route::get('/order/{orderId}', [ProductionOrderItemMaterialController::class, 'getByOrder']);
                Route::get('/order-item/{productionOrderItemId}', [ProductionOrderItemMaterialController::class, 'getByProductionOrderItem']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [ProductionOrderItemMaterialController::class, 'store']);
                Route::put('/{id}/update', [ProductionOrderItemMaterialController::class, 'update']);
                Route::delete('/{id}/delete', [ProductionOrderItemMaterialController::class, 'destroy']);
            });
            // Stock deduction/restoration is the inventory role's job (Permintaan Material)
            Route::middleware('role:admin,operator,inventory')->group(function () {
                Route::patch('/{id}/deduct', [ProductionOrderItemMaterialController::class, 'deduct']);
                Route::patch('/{id}/restore', [ProductionOrderItemMaterialController::class, 'restore']);
            });
        });

        // Production Order Item Images
        Route::prefix('order-item-image')->middleware('role:admin,operator')->group(function () {
            Route::post('/add', [ProductionOrderItemImageController::class, 'store']);
            Route::put('/{id}/update', [ProductionOrderItemImageController::class, 'update']);
            Route::delete('/{id}/delete', [ProductionOrderItemImageController::class, 'destroy']);
        });

        // SPK
        Route::prefix('spk')->group(function () {
            Route::middleware('role:admin,operator,owner')->group(function () {
                Route::get('/', [SpkController::class, 'index']);
                Route::get('/order/{productionOrderId}', [SpkController::class, 'showByProductionOrder']);
                Route::get('/{id}', [SpkController::class, 'show']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::post('/add', [SpkController::class, 'store']);
            });
        });

        // Production Schedules & Progress
        Route::prefix('schedule')->group(function () {
            Route::middleware('role:admin,operator,owner')->group(function () {
                Route::get('/order/{orderId}', [ProductionScheduleController::class, 'getByOrder']);
                Route::get('/order/{orderId}/progress', [ProductionScheduleController::class, 'getOrderProgress']);
                Route::get('/ongoing-progress', [ProductionScheduleController::class, 'getAllOngoingProgress']);
                Route::get('/team-schedules', [ProductionScheduleController::class, 'getTeamSchedules']);
                Route::get('/await-material-schedules', [ProductionScheduleController::class, 'getAwaitMaterialSchedules']);
                Route::get('/finished-summary', [ProductionScheduleController::class, 'getFinishedSummary']);
            });
            Route::middleware('role:admin,operator')->group(function () {
                Route::patch('/{id}/status', [ProductionScheduleController::class, 'updateStatus']);
            });
        });
    });

}); // end auth:sanctum + active
