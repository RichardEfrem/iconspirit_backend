<?php

namespace App\Services\Production;

use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductionOrderService
{
    /**
     * Retrieve a single production order by ID.
     * * @throws ModelNotFoundException
     */
    public function findById(int $id): ProductionOrder
    {
        return ProductionOrder::with('status')->findOrFail($id);
    }

    /**
     * Retrieve a list of production orders.
     * * @param int|null $perPage Pass an integer to enable pagination
     * @return Collection|LengthAwarePaginator
     */
    public function list(array $filters = [], ?int $perPage = null)
    {
        $query = ProductionOrder::with('status')->latest();

        // GLOBAL SEARCH: Checks both SPK Code OR Customer Name
        $query->when(isset($filters['search']), function ($q) use ($filters) {
            $searchTerm = '%' . $filters['search'] . '%';
            $q->where(function ($subQ) use ($searchTerm) {
                $subQ->where('kode_spk', 'ilike', $searchTerm)
                     ->orWhere('nama_customer', 'ilike', $searchTerm);
            });
        });

        // (You can keep these individual ones if you ever add advanced specific filters later)
        $query->when(isset($filters['kode_spk']), function ($q) use ($filters) {
            $q->where('kode_spk', 'ilike', '%' . $filters['kode_spk'] . '%');
        });
        $query->when(isset($filters['nama_customer']), function ($q) use ($filters) {
            $q->where('nama_customer', 'ilike', '%' . $filters['nama_customer'] . '%');
        });

        // Filter by Status ID (Exact Match)
        $query->when(isset($filters['status_id']), function ($q) use ($filters) {
            $q->where('status_id', $filters['status_id']);
        });

        // Filter by Date Range
        $query->when(isset($filters['start_date']) && isset($filters['end_date']), function ($q) use ($filters) {
            $q->whereBetween('tanggal_order', [$filters['start_date'], $filters['end_date']]);
        });

        // Filter by Single Specific Date
        $query->when(isset($filters['tanggal_order']) && !isset($filters['start_date']), function ($q) use ($filters) {
            $q->whereDate('tanggal_order', $filters['tanggal_order']);
        });

        return $perPage 
            ? $query->paginate($perPage) 
            : $query->get();
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): ProductionOrder
    {
        $validated = Validator::make($data, [
            'kode_spk' => ['required', 'string', 'max:255', 'unique:production_order,kode_spk'],
            'nama_customer' => ['required', 'string', 'max:255'],
            'alamat_customer' => ['required', 'string'],
            'tanggal_order' => ['required', 'date'],
            'status_id' => ['required', 'string', 'exists:production_statuses,id'],
        ])->validate();

        return ProductionOrder::create(Arr::only($validated, [
            'kode_spk',
            'nama_customer',
            'alamat_customer',
            'tanggal_order',
            'status_id',
        ]))->load('status');
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): ProductionOrder
    {
        $order = $this->findById($id);

        $validated = Validator::make($data, [
            'kode_spk' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('production_order', 'kode_spk')->ignore($id),
            ],
            'nama_customer' => ['sometimes', 'required', 'string', 'max:255'],
            'alamat_customer' => ['sometimes', 'required', 'string'],
            'tanggal_order' => ['sometimes', 'required', 'date'],
            'status_id' => ['sometimes', 'required', 'string', 'exists:production_statuses,id'],
        ])->validate();

        $order->update(Arr::only($validated, [
            'kode_spk',
            'nama_customer',
            'alamat_customer',
            'tanggal_order',
            'status_id',
        ]));

        return $order->fresh()->load('status');
    }
}
