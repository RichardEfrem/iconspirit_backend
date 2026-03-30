<?php

namespace App\Services\Production;

use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamService
{
	public function getAll(): Collection
	{
		return Team::query()
			->with('station')
			->latest()
			->get();
	}

	public function getById(int $id): Team
	{
		return Team::query()
			->with('station')
			->findOrFail($id);
	}

	/**
	 * @throws ValidationException
	 */
	public function create(array $data): Team
	{
		$validated = Validator::make($data, [
			'kode_team' => ['required', 'string', 'max:255', 'unique:team,kode_team'],
			'station_id' => ['required', 'integer', 'exists:station,id'],
		])->validate();

		return Team::create($validated)->load('station');
	}

	/**
	 * @throws ValidationException
	 * @throws ModelNotFoundException
	 */
	public function update(int $id, array $data): Team
	{
		$team = Team::findOrFail($id);

		$validated = Validator::make($data, [
			'kode_team' => [
				'sometimes',
				'required',
				'string',
				'max:255',
				Rule::unique('team', 'kode_team')->ignore($id),
			],
			'station_id' => ['sometimes', 'required', 'integer', 'exists:station,id'],
		])->validate();

		$team->update($validated);

		return $team->fresh()->load('station');
	}

	/**
	 * @throws ModelNotFoundException
	 */
	public function delete(int $id): void
	{
		$team = Team::findOrFail($id);
		$team->delete();
	}
}
