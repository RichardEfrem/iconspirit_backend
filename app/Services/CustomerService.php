<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    /**
     * Retrieve a single customer by ID.
     * @throws ModelNotFoundException
     */
    public function findById(int $id): Customer
    {
        return Customer::findOrFail($id);
    }

    /**
     * List customers, optional pagination.
     * @return Collection|LengthAwarePaginator
     */
    public function list(?int $perPage = null)
    {
        $query = Customer::latest();

        return $perPage
            ? $query->paginate($perPage)
            : $query->get();
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): Customer
    {
        $validated = Validator::make($data, [
            'nama' => ['required', 'string', 'max:255'],
            'alamat' => ['required', 'string'],
            'nomor_telp' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ])->validate();

        return Customer::create($validated);
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Customer
    {
        $customer = $this->findById($id);

        $validated = Validator::make($data, [
            'nama' => ['sometimes', 'required', 'string', 'max:255'],
            'alamat' => ['sometimes', 'required', 'string'],
            'nomor_telp' => ['sometimes', 'required', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ])->validate();

        $customer->update($validated);

        return $customer->fresh();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function delete(int $id): bool
    {
        $customer = $this->findById($id);

        return $customer->delete();
    }
}
