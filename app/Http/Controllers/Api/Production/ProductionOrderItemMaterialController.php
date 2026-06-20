<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\ProductionOrderItemMaterialService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemMaterialController extends Controller
{
    /**
     * Get a list of all production order item materials.
     * Supports ?per_page query parameter.
     */
    public function index(Request $request, ProductionOrderItemMaterialService $service)
    {
        $perPage = $request->query('per_page');
        $result = $service->list($perPage);

        return $this->successResponse($result, 'Production order item materials retrieved successfully');
    }

    /**
     * Get a specific item material by its ID.
     */
    public function show(ProductionOrderItemMaterialService $service, int $id)
    {
        try {
            $result = $service->findById($id);

            return $this->successResponse($result, 'Production order item material retrieved successfully');
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Production order item material not found', 404);
        }
    }

    /**
     * Get all materials belonging to a specific Production Order via order items.
     */
    public function getByOrder(ProductionOrderItemMaterialService $service, int $orderId)
    {
        $result = $service->getByOrderId($orderId);

        return $this->successResponse($result, 'Materials for the production order retrieved successfully');
    }

    /**
     * Get all materials belonging to a specific Production Order Item.
     */
    public function getByProductionOrderItem(ProductionOrderItemMaterialService $service, int $productionOrderItemId)
    {
        $result = $service->getByProductionOrderItemId($productionOrderItemId);

        return $this->successResponse($result, 'Materials for the production order item retrieved successfully');
    }

    /**
     * Store a new item material.
     */
    public function store(Request $request, ProductionOrderItemMaterialService $service)
    {
        try {
            $result = $service->create($request->only([
                'production_order_item_id', 'material_id', 'quantity', 'cost',
            ]));

            return $this->successResponse($result, 'Production order item material created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    /**
     * Update an existing item material.
     */
    public function update(Request $request, ProductionOrderItemMaterialService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->only([
                'production_order_item_id', 'material_id', 'quantity', 'cost',
            ]));

            return $this->successResponse($result, 'Production order item material updated successfully');
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Production order item material not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    /**
     * Delete an item material.
     */
    public function destroy(ProductionOrderItemMaterialService $service, int $id)
    {
        try {
            $service->delete($id);

            return $this->successResponse(null, 'Production order item material deleted successfully');
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Production order item material not found', 404);
        }
    }

    /**
     * Deduct inventory stock for a material entry (checkbox checked).
     */
    public function deduct(ProductionOrderItemMaterialService $service, int $id)
    {
        try {
            $result = $service->deductMaterial($id);

            return $this->successResponse($result, 'Material stock deducted successfully');
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Production order item material not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    /**
     * Restore inventory stock for a material entry (checkbox unchecked).
     */
    public function restore(ProductionOrderItemMaterialService $service, int $id)
    {
        try {
            $result = $service->restoreMaterial($id);

            return $this->successResponse($result, 'Material stock restored successfully');
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Production order item material not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }
}
