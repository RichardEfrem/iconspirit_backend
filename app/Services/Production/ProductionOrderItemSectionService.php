<?php

namespace App\Services\Production;

use App\Models\ProductionOrderItemSection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemSectionService
{
    private const RELATIONS = ['order'];

    /**
     * Retrieve a single section by ID.
     * @throws ModelNotFoundException
     */
    public function findById(int $id): ProductionOrderItemSection
    {
        return ProductionOrderItemSection::with(self::RELATIONS)->findOrFail($id);
    }

    /**
     * Retrieve all sections for a specific Production Order.
     */
    public function getByOrderId(int $orderId): Collection
    {
        return ProductionOrderItemSection::with(self::RELATIONS)
            ->where('production_order_id', $orderId)
            ->get();
    }

    /**
     * Retrieve a list of sections with optional pagination.
     * @return Collection|LengthAwarePaginator
     */
    public function list(?int $perPage = null)
    {
        $query = ProductionOrderItemSection::with(self::RELATIONS)->latest();

        return $perPage
            ? $query->paginate($perPage)
            : $query->get();
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data): ProductionOrderItemSection
    {
        $validated = Validator::make($data, [
            'production_order_id' => ['required', 'integer', 'exists:production_order,id'],
            'name' => ['required', 'string', 'max:255'],
        ])->validate();

        return ProductionOrderItemSection::create($validated)->load(self::RELATIONS);
    }

    /**
     * @throws ValidationException
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): ProductionOrderItemSection
    {
        $section = $this->findById($id);

        $validated = Validator::make($data, [
            'production_order_id' => ['sometimes', 'required', 'integer', 'exists:production_order,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ])->validate();

        $section->update($validated);

        return $section->fresh()->load(self::RELATIONS);
    }

    public function delete(int $id): bool
    {
        $section = $this->findById($id);

        return $section->delete();
    }
}