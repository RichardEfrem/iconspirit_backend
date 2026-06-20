<?php

use App\Services\Production\SchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('scheduleUnassignedItems throws DomainException when lock is held', function () {
    $this->seed();

    // Manually acquire the global scheduling lock to simulate a concurrent worker.
    $lock = Cache::lock('scheduling:global', 60);
    expect($lock->get())->toBeTrue();

    try {
        $service = app(SchedulingService::class);

        // The second caller should fail-fast after the wait window (10s in prod,
        // but with array cache the lock is in-process so block() returns false
        // almost immediately once we know it is held).
        expect(fn() => $service->scheduleUnassignedItems())
            ->toThrow(\DomainException::class);
    } finally {
        $lock->release();
    }
});

test('confirmMaterialArrival is guarded by the same scheduling lock', function () {
    $this->seed();

    $lock = Cache::lock('scheduling:global', 60);
    expect($lock->get())->toBeTrue();

    try {
        $service = app(SchedulingService::class);

        expect(fn() => $service->confirmMaterialArrival(99999))
            ->toThrow(\DomainException::class);
    } finally {
        $lock->release();
    }
});

test('lock is released after a successful scheduling run', function () {
    $this->seed();

    app(SchedulingService::class)->scheduleUnassignedItems();

    // After the call returns, the lock must be free so the next caller can take it.
    $lock = Cache::lock('scheduling:global', 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});
