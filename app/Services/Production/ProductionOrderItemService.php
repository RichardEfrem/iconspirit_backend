<?php

namespace App\Services\Production;

use App\Models\ProductionOrderItem;
use App\Models\ProductionOrderItemSection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemService
{
    private const RELATIONS = ['order', 'section', 'product', 'images', 'materials.material'];

    /**
     * Retrieve a single item by ID.
     * @throws ModelNotFoundException
     */
    public function findById(int $id): ProductionOrderItem
    {
        return ProductionOrderItem::with(self::RELATIONS)->findOrFail($id);
    }

    /**
     * Retrieve all items for a specific Production Order.
     */
    public function getByOrderId(int $orderId): Collection
    {
        return ProductionOrderItem::with(self::RELATIONS)
            ->where('production_order_id', $orderId)
            ->get();
    }

    /**
     * Retrieve a list of items with optional pagination.
     * @return Collection|LengthAwarePaginator
     */
    public function list(?int $perPage = null)
    {
        $query = ProductionOrderItem::with(self::RELATIONS)->latest();

        return $perPage 
            ? $query->paginate($perPage) 
            : $query->get();
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): ProductionOrderItem
    {
        $data = $this->normalizeCreatePayload($data);

        $validator = Validator::make($data, [
            'production_order_id' => ['required', 'integer', 'exists:production_order,id'],
            'production_order_item_section_id' => ['nullable', 'integer', 'exists:production_order_item_section,id', 'required_without:production_order_item_section_name'],
            'production_order_item_section_name' => ['nullable', 'string', 'max:255', 'required_without:production_order_item_section_id'],
            'product_id' => ['required', 'integer', 'exists:product,id'],
            'panjang' => ['required', 'integer', 'min:0'],
            'tinggi' => ['required', 'integer', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'keterangan' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($data) {
            if (!isset($data['production_order_id'], $data['production_order_item_section_id'])) {
                return;
            }

            $isOwnedByOrder = ProductionOrderItemSection::query()
                ->where('id', $data['production_order_item_section_id'])
                ->where('production_order_id', $data['production_order_id'])
                ->exists();

            if (!$isOwnedByOrder) {
                $validator->errors()->add(
                    'production_order_item_section_id',
                    'The selected section does not belong to the selected production order.'
                );
            }
        });

        $validated = $validator->validate();

        $validated['production_order_item_section_id'] = $this->resolveSectionIdForCreate($validated);
        unset($validated['production_order_item_section_name']);

        return ProductionOrderItem::create($validated)->load(self::RELATIONS);
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): ProductionOrderItem
    {
        $item = $this->findById($id);

        $validator = Validator::make($data, [
            'production_order_id' => ['sometimes', 'required', 'integer', 'exists:production_order,id'],
            'production_order_item_section_id' => ['sometimes', 'required', 'integer', 'exists:production_order_item_section,id'],
            'product_id' => ['sometimes', 'required', 'integer', 'exists:product,id'],
            'panjang' => ['sometimes', 'required', 'integer', 'min:0'],
            'tinggi' => ['sometimes', 'required', 'integer', 'min:0'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1'],
            'keterangan' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($data, $item) {
            $resolvedOrderId = $data['production_order_id'] ?? $item->production_order_id;
            $resolvedSectionId = $data['production_order_item_section_id'] ?? $item->production_order_item_section_id;

            if (empty($resolvedSectionId)) {
                return;
            }

            $isOwnedByOrder = ProductionOrderItemSection::query()
                ->where('id', $resolvedSectionId)
                ->where('production_order_id', $resolvedOrderId)
                ->exists();

            if (!$isOwnedByOrder) {
                $validator->errors()->add(
                    'production_order_item_section_id',
                    'The selected section does not belong to the selected production order.'
                );
            }
        });

        $validated = $validator->validate();

        $item->update($validated);

        return $item->fresh()->load(self::RELATIONS);
    }

    public function delete(int $id): bool
    {
        $item = $this->findById($id);
        
        return $item->delete();
    }

    /**
     * Normalize accepted aliases for section input on item creation.
     */
    private function normalizeCreatePayload(array $data): array
    {
        if (!array_key_exists('production_order_item_section_name', $data)) {
            $data['production_order_item_section_name'] = $data['section_name'] ?? $data['section'] ?? null;
        }

        if (array_key_exists('production_order_item_section_name', $data) && is_string($data['production_order_item_section_name'])) {
            $data['production_order_item_section_name'] = trim($data['production_order_item_section_name']);

            if ($data['production_order_item_section_name'] === '') {
                $data['production_order_item_section_name'] = null;
            }
        }

        if (array_key_exists('production_order_item_section_id', $data) && $data['production_order_item_section_id'] === '') {
            $data['production_order_item_section_id'] = null;
        }

        return $data;
    }

    /**
     * Resolve section ID from an explicit ID or by section name per production order.
     */
    private function resolveSectionIdForCreate(array $validated): int
    {
        if (!empty($validated['production_order_item_section_id'])) {
            return (int) $validated['production_order_item_section_id'];
        }

        $section = ProductionOrderItemSection::query()->firstOrCreate([
            'production_order_id' => $validated['production_order_id'],
            'name' => $validated['production_order_item_section_name'],
        ]);

        return $section->id;
    }
}