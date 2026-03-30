<?php

namespace App\Services\Production;

use App\Models\LocationTransitTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LocationTransitTimeService
{
    public function getAll(): Collection
    {
        return LocationTransitTime::query()
            ->with(['originFactory', 'destinationFactory'])
            ->latest()
            ->get();
    }

    public function getById(int $id): LocationTransitTime
    {
        return LocationTransitTime::query()
            ->with(['originFactory', 'destinationFactory'])
            ->findOrFail($id);
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): LocationTransitTime
    {
        $validated = Validator::make($data, [
            'origin_factory_id' => ['required', 'integer', 'exists:factory_location,id', 'different:destination_factory_id'],
            'destination_factory_id' => ['required', 'integer', 'exists:factory_location,id'],
            'transit_time' => ['required', 'integer', 'min:0'],
        ])->validate();

        return LocationTransitTime::create($validated)->load(['originFactory', 'destinationFactory']);
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): LocationTransitTime
    {
        $transitTime = LocationTransitTime::findOrFail($id);

        $validated = Validator::make($data, [
            'origin_factory_id' => ['sometimes', 'required', 'integer', 'exists:factory_location,id', 'different:destination_factory_id'],
            'destination_factory_id' => ['sometimes', 'required', 'integer', 'exists:factory_location,id', 'different:origin_factory_id'],
            'transit_time' => ['sometimes', 'required', 'integer', 'min:0'],
        ])->validate();

        $transitTime->update($validated);

        return $transitTime->fresh()->load(['originFactory', 'destinationFactory']);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function delete(int $id): void
    {
        $transitTime = LocationTransitTime::findOrFail($id);
        $transitTime->delete();
    }
}