<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\ProductionOrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionOrderController extends Controller
{
    /**
     * Get a list of production orders.
     * Use ?per_page=X for pagination.
     */
    public function index(Request $request, ProductionOrderService $service)
    {
        // 1. Validate the date range (optional but highly recommended)
        try {
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date'   => 'nullable|date|after_or_equal:start_date',
            ]);
        } catch (ValidationException $e) {
            return $this->errorResponse('Invalid date range provided', 422, $e->errors());
        }

        // 2. Extract allowed filters, including the date range
        // 2. Extract allowed filters
        $filters = $request->only([
            'search', // <-- ADD THIS
            'kode_spk',
            'nama_customer',
            'status_id',
            'tanggal_order',
            'start_date',
            'end_date'
        ]);

        $perPage = $request->query('per_page');

        // 3. Pass filters to the service
        $result = $service->list($filters, $perPage);

        return $this->successResponse($result, 'Production orders retrieved successfully');
    }

    /**
     * Get a single production order by ID.
     */
    public function show(ProductionOrderService $service, int $id)
    {
        try {
            $result = $service->findById($id);

            return $this->successResponse($result, 'Production order retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        }
    }

    public function store(Request $request, ProductionOrderService $service)
    {
        try {
            $result = $service->create($request->all());

            return $this->successResponse($result, 'Production order created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function update(Request $request, ProductionOrderService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->all());

            return $this->successResponse($result, 'Production order updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }
}
