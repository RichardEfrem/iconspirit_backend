<?php

namespace App\Observers;

use App\Models\ProductionOrder;

class ProductionOrderObserver
{
    /**
     * Handle the ProductionOrder "created" event.
     */
    public function created(ProductionOrder $productionOrder): void
    {
        //
    }

    /**
     * Handle the ProductionOrder "updated" event.
     */
    public function updated(ProductionOrder $productionOrder): void
    {
        if ($productionOrder->wasChanged('status_id') && $productionOrder->status_id === 'await_material') {
            app(\App\Services\Production\SchedulingService::class)->scheduleUnassignedItems();
        }
    }

    /**
     * Handle the ProductionOrder "deleted" event.
     */
    public function deleted(ProductionOrder $productionOrder): void
    {
        //
    }

    /**
     * Handle the ProductionOrder "restored" event.
     */
    public function restored(ProductionOrder $productionOrder): void
    {
        //
    }

    /**
     * Handle the ProductionOrder "force deleted" event.
     */
    public function forceDeleted(ProductionOrder $productionOrder): void
    {
        //
    }
}
