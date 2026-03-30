<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\ProductionOrderItemService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemController extends Controller
{
    /**
     * Get a list of all production order items.
     * Supports ?per_page query parameter.
     */
    public function index(Request $request, ProductionOrderItemService $service)
    {
        $perPage = $request->query('per_page');
        $result = $service->list($perPage);

        return $this->successResponse($result, 'Production order items retrieved successfully');
    }

    /**
     * Get a specific item by its ID.
     */
    public function show(ProductionOrderItemService $service, int $id)
    {
        try {
            $result = $service->findById($id);

            return $this->successResponse($result, 'Production order item retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order item not found', 404);
        }
    }

    /**
     * Get all items belonging to a specific Production Order (SPK).
     */
    public function getByOrder(ProductionOrderItemService $service, int $orderId)
    {
        $result = $service->getByOrderId($orderId);

        return $this->successResponse($result, 'Items for the production order retrieved successfully');
    }

    /**
     * Store a new item.
     */
    public function store(Request $request, ProductionOrderItemService $service)
    {
        try {
            $result = $service->create($request->all());

            return $this->successResponse($result, 'Production order item created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    /**
     * Update an existing item.
     */
    public function update(Request $request, ProductionOrderItemService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->all());

            return $this->successResponse($result, 'Production order item updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order item not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }
}