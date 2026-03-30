<?php

namespace App\Services\Production;

use App\Models\FactoryLocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LocationService
{
	public function getAll(): Collection
	{
		return FactoryLocation::query()
			->orderBy('priority')
			->get();
	}

	public function getById(int $id): FactoryLocation
	{
		return FactoryLocation::findOrFail($id);
	}

	/**
	 * @throws ValidationException
	 */
	public function create(array $data): FactoryLocation
	{
		$validated = Validator::make($data, [
			'nama_factory' => ['required', 'string', 'max:255'],
			'alamat_factory' => ['required', 'string', 'max:255'],
			'priority' => ['required', 'integer', 'min:1'],
		])->validate();

		return FactoryLocation::create($validated);
	}

	/**
	 * @throws ValidationException
	 * @throws ModelNotFoundException
	 */
	public function update(int $id, array $data): FactoryLocation
	{
		$location = FactoryLocation::findOrFail($id);

		$validated = Validator::make($data, [
			'nama_factory' => ['sometimes', 'required', 'string', 'max:255'],
			'alamat_factory' => ['sometimes', 'required', 'string', 'max:255'],
			'priority' => ['sometimes', 'required', 'integer', 'min:1'],
		])->validate();

		$location->update($validated);

		return $location->fresh();
	}

	/**
	 * @throws ModelNotFoundException
	 */
	public function delete(int $id): void
	{
		$location = FactoryLocation::findOrFail($id);
		$location->delete();
	}
}
