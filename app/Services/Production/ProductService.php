<?php

namespace App\Services\Production;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductService
{
	public function getAll(): Collection
	{
		return Product::query()->latest()->get();
	}

	public function getById(int $id): Product
	{
		return Product::findOrFail($id);
	}

	/**
	 * @throws ValidationException
	 */
	public function create(array $data): Product
	{
		$validated = Validator::make($data, [
			'kode_product' => ['required', 'string', 'max:255', 'unique:product,kode_product'],
			'nama_product' => ['required', 'string', 'max:255'],
		])->validate();

		return Product::create($validated);
	}

	/**
	 * @throws ValidationException
	 * @throws ModelNotFoundException
	 */
	public function update(int $id, array $data): Product
	{
		$product = Product::findOrFail($id);

		$validated = Validator::make($data, [
			'kode_product' => [
				'sometimes',
				'required',
				'string',
				'max:255',
				Rule::unique('product', 'kode_product')->ignore($id),
			],
			'nama_product' => ['sometimes', 'required', 'string', 'max:255'],
		])->validate();

		$product->update($validated);

		return $product->fresh();
	}

	/**
	 * @throws ModelNotFoundException
	 */
	public function delete(int $id): void
	{
		$product = Product::findOrFail($id);
		$product->delete();
	}
}
