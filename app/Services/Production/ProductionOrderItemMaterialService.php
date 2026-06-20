<?php

namespace App\Services\Production;

use App\Models\ProductionOrderItemMaterial;
use App\Services\Inventory\MaterialService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemMaterialService
{
    public function __construct(
        private MaterialService $materialService
    ) {
    }

    /**
     * Retrieve a single item material by ID.
     * @throws ModelNotFoundException
     */
    public function findById(int $id): ProductionOrderItemMaterial
    {
        return ProductionOrderItemMaterial::with(['productionOrderItem.order', 'material'])->findOrFail($id);
    }

    /**
     * Retrieve all materials for a specific Production Order.
     */
    public function getByOrderId(int $orderId): Collection
    {
        return ProductionOrderItemMaterial::with(['productionOrderItem.order', 'material'])
            ->whereHas('productionOrderItem', function ($query) use ($orderId) {
                $query->where('production_order_id', $orderId);
            })
            ->get();
    }

    /**
     * Retrieve all materials for a specific Production Order Item.
     */
    public function getByProductionOrderItemId(int $productionOrderItemId): Collection
    {
        return ProductionOrderItemMaterial::with(['productionOrderItem.order', 'material'])
            ->where('production_order_item_id', $productionOrderItemId)
            ->get();
    }

    /**
     * Retrieve a list of item materials with optional pagination.
     * @return Collection|LengthAwarePaginator
     */
    public function list(?int $perPage = null)
    {
        $query = ProductionOrderItemMaterial::with(['productionOrderItem.order', 'material'])->latest();

        return $perPage
            ? $query->paginate($perPage)
            : $query->get();
    }

    /**
     * Create a new item material entry WITHOUT deducting inventory stock.
     * Stock deduction is handled separately via deductMaterial().
     * @throws ValidationException
     */
    public function create(array $data): ProductionOrderItemMaterial
    {
        $validated = Validator::make($data, [
            'production_order_item_id' => ['required', 'integer', 'exists:production_order_item,id'],
            'material_id' => ['required', 'integer', 'exists:material,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'cost' => ['required', 'numeric', 'min:0'],
        ])->validate();

        $validated['is_deducted'] = false;

        return ProductionOrderItemMaterial::create($validated)
            ->load(['productionOrderItem.order', 'material']);
    }

    /**
     * Update an existing item material entry.
     * If the material was already deducted, adjusts stock accordingly.
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): ProductionOrderItemMaterial
    {
        $itemMaterial = $this->findById($id);

        $validated = Validator::make($data, [
            'production_order_item_id' => ['sometimes', 'required', 'integer', 'exists:production_order_item,id'],
            'material_id' => ['sometimes', 'required', 'integer', 'exists:material,id'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'cost' => ['sometimes', 'required', 'numeric', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($itemMaterial, $validated) {
            // Only adjust stock if stock was already deducted
            if ($itemMaterial->is_deducted) {
                $oldMaterialId = (int) $itemMaterial->material_id;
                $oldQuantity = (int) $itemMaterial->quantity;
                $newMaterialId = (int) ($validated['material_id'] ?? $oldMaterialId);
                $newQuantity = (int) ($validated['quantity'] ?? $oldQuantity);

                if ($oldMaterialId !== $newMaterialId) {
                    $this->materialService->updateStock($oldMaterialId, $oldQuantity);
                    $this->materialService->updateStock($newMaterialId, -$newQuantity);
                } elseif ($oldQuantity !== $newQuantity) {
                    $diff = $oldQuantity - $newQuantity;
                    $this->materialService->updateStock($oldMaterialId, $diff);
                }
            }

            $itemMaterial->update($validated);

            return $itemMaterial->fresh()->load(['productionOrderItem.order', 'material']);
        });
    }

    /**
     * Delete an item material entry.
     * Only restores inventory stock if it was previously deducted.
     * @throws ModelNotFoundException
     */
    public function delete(int $id): bool
    {
        $itemMaterial = $this->findById($id);

        return DB::transaction(function () use ($itemMaterial) {
            if ($itemMaterial->is_deducted) {
                $this->materialService->updateStock(
                    (int) $itemMaterial->material_id,
                    (int) $itemMaterial->quantity
                );
            }

            return $itemMaterial->delete();
        });
    }

    /**
     * Deduct inventory stock for a material entry and mark it as deducted.
     * Fails if the entry is already deducted or stock is insufficient.
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function deductMaterial(int $id): ProductionOrderItemMaterial
    {
        return DB::transaction(function () use ($id) {
            // Lock the row so concurrent requests wait until this transaction commits
            $itemMaterial = ProductionOrderItemMaterial::lockForUpdate()->findOrFail($id);

            if ($itemMaterial->is_deducted) {
                throw ValidationException::withMessages([
                    'is_deducted' => ['Material ini sudah dikurangi dari stok.'],
                ]);
            }

            $this->materialService->updateStock(
                (int) $itemMaterial->material_id,
                -((int) $itemMaterial->quantity)
            );

            $itemMaterial->update(['is_deducted' => true]);

            return $itemMaterial->fresh()->load(['productionOrderItem.order', 'material']);
        });
    }

    /**
     * Restore inventory stock for a material entry and mark it as not deducted.
     * Fails if the entry was not deducted.
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function restoreMaterial(int $id): ProductionOrderItemMaterial
    {
        return DB::transaction(function () use ($id) {
            // Lock the row so concurrent requests wait until this transaction commits
            $itemMaterial = ProductionOrderItemMaterial::lockForUpdate()->findOrFail($id);

            if (! $itemMaterial->is_deducted) {
                throw ValidationException::withMessages([
                    'is_deducted' => ['Material ini belum dikurangi dari stok.'],
                ]);
            }

            $this->materialService->updateStock(
                (int) $itemMaterial->material_id,
                (int) $itemMaterial->quantity
            );

            $itemMaterial->update(['is_deducted' => false]);

            return $itemMaterial->fresh()->load(['productionOrderItem.order', 'material']);
        });
    }
}
