<?php

namespace App\Services\Production;

use App\Models\Station;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StationService
{
	public function getAll(): Collection
	{
		return Station::query()
			->with('factoryLocation')
			->latest()
			->get();
	}

	public function getById(int $id): Station
	{
		return Station::query()
			->with(['factoryLocation', 'teams'])
			->findOrFail($id);
	}

	/**
	 * @throws ValidationException
	 */
	public function create(array $data): Station
	{
		$validated = Validator::make($data, [
			'nama_station' => ['required', 'string', 'max:255'],
			'factory_location_id' => ['required', 'integer', 'exists:factory_location,id'],
			'biaya_harian' => ['required', 'integer', 'min:0'],
		])->validate();

		return Station::create($validated)->load('factoryLocation');
	}

	/**
	 * @throws ValidationException
	 * @throws ModelNotFoundException
	 */
	public function update(int $id, array $data): Station
	{
		$station = Station::findOrFail($id);

		$validated = Validator::make($data, [
			'nama_station' => ['sometimes', 'required', 'string', 'max:255'],
			'factory_location_id' => ['sometimes', 'required', 'integer', 'exists:factory_location,id'],
			'biaya_harian' => ['sometimes', 'required', 'integer', 'min:0'],
		])->validate();

		$station->update($validated);

		return $station->fresh()->load('factoryLocation');
	}

	/**
	 * @throws ModelNotFoundException
	 */
	public function delete(int $id): void
	{
		$station = Station::findOrFail($id);
		$station->delete();
	}
}
