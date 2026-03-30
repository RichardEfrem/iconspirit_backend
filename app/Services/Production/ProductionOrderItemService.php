<?php

namespace App\Services\Production;

use App\Models\ProductionOrderItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemService
{
    /**
     * Retrieve a single item by ID.
     * @throws ModelNotFoundException
     */
    public function findById(int $id): ProductionOrderItem
    {
        return ProductionOrderItem::with(['order', 'product'])->findOrFail($id);
    }

    /**
     * Retrieve all items for a specific Production Order.
     */
    public function getByOrderId(int $orderId): Collection
    {
        return ProductionOrderItem::with(['product'])
            ->where('production_order_id', $orderId)
            ->get();
    }

    /**
     * Retrieve a list of items with optional pagination.
     * @return Collection|LengthAwarePaginator
     */
    public function list(?int $perPage = null)
    {
        $query = ProductionOrderItem::with(['order', 'product'])->latest();

        return $perPage 
            ? $query->paginate($perPage) 
            : $query->get();
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): ProductionOrderItem
    {
        $validated = Validator::make($data, [
            'production_order_id' => ['required', 'integer', 'exists:production_order,id'],
            'product_id' => ['required', 'integer', 'exists:product,id'],
            'spesifikasi_produk' => ['required', 'string'],
            'panjang' => ['required', 'integer', 'min:0'],
            'lebar' => ['required', 'integer', 'min:0'],
            'tinggi' => ['nullable', 'integer', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'keterangan' => ['nullable', 'string'],
        ])->validate();

        return ProductionOrderItem::create($validated)->load(['order', 'product']);
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): ProductionOrderItem
    {
        $item = $this->findById($id);

        $validated = Validator::make($data, [
            'production_order_id' => ['sometimes', 'required', 'integer', 'exists:production_order,id'],
            'product_id' => ['sometimes', 'required', 'integer', 'exists:product,id'],
            'spesifikasi_produk' => ['sometimes', 'required', 'string'],
            'panjang' => ['sometimes', 'required', 'integer', 'min:0'],
            'lebar' => ['sometimes', 'required', 'integer', 'min:0'],
            'tinggi' => ['nullable', 'integer', 'min:0'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1'],
            'keterangan' => ['nullable', 'string'],
        ])->validate();

        $item->update($validated);

        return $item->fresh()->load(['order', 'product']);
    }
}