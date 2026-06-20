<?php

namespace App\Services\Production;

use App\Models\ProductionOrderItemImage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemImageService
{
    /**
     * Retrieve a single item image by ID.
     * @throws ModelNotFoundException
     */
    public function findById(int $id): ProductionOrderItemImage
    {
        return ProductionOrderItemImage::with(['productionOrderItem.order', 'productionOrderItem.product'])->findOrFail($id);
    }

    /**
     * Create a new item image entry.
     * @throws ValidationException
     */
    public function create(array $data): ProductionOrderItemImage
    {
        $validated = Validator::make($data, [
            'image_path' => ['required', 'string', 'max:2048'],
            'production_order_item_id' => ['required', 'integer', 'exists:production_order_item,id'],
        ])->validate();

        return ProductionOrderItemImage::create($validated)->load(['productionOrderItem.order', 'productionOrderItem.product']);
    }

    /**
     * Update an existing item image entry.
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): ProductionOrderItemImage
    {
        $itemImage = $this->findById($id);

        $validated = Validator::make($data, [
            'image_path' => ['sometimes', 'required', 'string', 'max:2048'],
            'production_order_item_id' => ['sometimes', 'required', 'integer', 'exists:production_order_item,id'],
        ])->validate();

        $itemImage->update($validated);

        return $itemImage->fresh()->load(['productionOrderItem.order', 'productionOrderItem.product']);
    }

    /**
     * Delete an item image entry by ID.
     * @throws ModelNotFoundException
     */
    public function delete(int $id): bool
    {
        $itemImage = $this->findById($id);

        return $itemImage->delete();
    }
}
