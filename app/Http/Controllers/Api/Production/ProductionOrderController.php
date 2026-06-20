<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\ProductionOrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'order_id',
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
            $result = $service->create($request->only([
                'customer_id', 'nama_customer', 'alamat_customer',
                'nomor_telp', 'email', 'tanggal_order', 'status_id', 'is_urgent',
            ]));

            return $this->successResponse($result, 'Production order created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function update(Request $request, ProductionOrderService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->only([
                'tanggal_order', 'status_id', 'is_urgent', 'production_deadline',
            ]));

            return $this->successResponse($result, 'Production order updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function markAsAwaitMaterial(ProductionOrderService $service, int $id)
    {
        try {
            $result = $service->markAsAwaitMaterial($id, 'await_material');

            return $this->successResponse($result, 'Production order status updated to await material successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function confirmMaterialArrival(\App\Services\Production\SchedulingService $service, int $id)
    {
        try {
            $service->confirmMaterialArrival($id);

            return $this->successResponse(null, 'Material arrival confirmed successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to confirm material arrival', 500);
        }
    }

    /**
     * R6: Batch-confirm material arrival for multiple orders at once.
     */
    public function confirmMaterialArrivalBatch(Request $request, \App\Services\Production\SchedulingService $service)
    {
        try {
            $validated = $request->validate([
                'order_ids'   => ['required', 'array', 'min:1'],
                'order_ids.*' => ['integer', 'exists:production_order,id'],
            ]);

            $service->confirmMaterialArrivalBatch($validated['order_ids']);

            return $this->successResponse(null, 'Batch material arrival confirmed successfully');
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('Batch material confirmation failed', ['error' => $e->getMessage()]);
            return $this->errorResponse('Batch confirmation failed. Please try again.', 500);
        }
    }

    /**
     * R1: Get all orders whose material_eta has passed.
     */
    public function getOverdueMaterial(ProductionOrderService $service)
    {
        $result = $service->getOverdueMaterialOrders();
        return $this->successResponse($result, 'Overdue material orders retrieved successfully');
    }

    /**
     * R1: Extend material ETA for a specific overdue order.
     */
    public function extendMaterialEta(Request $request, ProductionOrderService $service, int $id)
    {
        try {
            $validated = $request->validate([
                'new_eta' => ['required', 'date', 'after:today'],
            ]);

            $result = $service->extendMaterialEta($id, $validated['new_eta']);
            return $this->successResponse($result, 'Material ETA extended successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function runScheduling(Request $request, \App\Services\Production\SchedulingService $service)
    {
        try {
            $service->scheduleUnassignedItems();
            return $this->successResponse(null, 'Batch scheduling completed successfully');
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('Scheduling failed', ['error' => $e->getMessage()]);
            return $this->errorResponse('Scheduling failed. Please try again.', 500);
        }
    }

    public function destroy(ProductionOrderService $service, int $id)
    {
        try {
            $service->delete($id);

            return $this->successResponse(null, 'Production order deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }
}

