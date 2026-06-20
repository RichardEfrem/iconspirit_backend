<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\ProductionOrderItemSectionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemSectionController extends Controller
{
    /**
     * Get a list of all production order item sections.
     * Supports ?per_page query parameter.
     */
    public function index(Request $request, ProductionOrderItemSectionService $service)
    {
        $perPage = $request->query('per_page');
        $result = $service->list($perPage);

        return $this->successResponse($result, 'Production order item sections retrieved successfully');
    }

    /**
     * Get a specific section by its ID.
     */
    public function show(ProductionOrderItemSectionService $service, int $id)
    {
        try {
            $result = $service->findById($id);

            return $this->successResponse($result, 'Production order item section retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order item section not found', 404);
        }
    }

    /**
     * Get all sections belonging to a specific Production Order.
     */
    public function getByOrder(ProductionOrderItemSectionService $service, int $orderId)
    {
        $result = $service->getByOrderId($orderId);

        return $this->successResponse($result, 'Sections for the production order retrieved successfully');
    }

    /**
     * Store a new section.
     */
    public function store(Request $request, ProductionOrderItemSectionService $service)
    {
        try {
            $result = $service->create($request->only([
                'production_order_id', 'production_order_item_id', 'section_id', 'name', 'section',
            ]));

            return $this->successResponse($result, 'Production order item section created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    /**
     * Update an existing section.
     */
    public function update(Request $request, ProductionOrderItemSectionService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->only([
                'production_order_id', 'production_order_item_id', 'section_id', 'name', 'section',
            ]));

            return $this->successResponse($result, 'Production order item section updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order item section not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    /**
     * Delete a section.
     */
    public function destroy(ProductionOrderItemSectionService $service, int $id)
    {
        try {
            $service->delete($id);

            return $this->successResponse(null, 'Production order item section deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order item section not found', 404);
        }
    }
}