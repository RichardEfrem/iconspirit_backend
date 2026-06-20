<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\ProductionScheduleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductionScheduleController extends Controller
{
    /**
     * Get all schedules for a specific production order.
     */
    public function getByOrder(ProductionScheduleService $service, int $orderId)
    {
        try {
            $result = $service->getSchedulesByOrderId($orderId);
            return $this->successResponse($result, 'Schedules retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        }
    }

    /**
     * Get aggregated progress for a specific production order.
     */
    public function getOrderProgress(ProductionScheduleService $service, int $orderId)
    {
        try {
            $result = $service->getOrderProgress($orderId);
            return $this->successResponse($result, 'Order progress retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Production order not found', 404);
        }
    }

    /**
     * Get progress for all ongoing orders (dashboard view).
     */
    public function getAllOngoingProgress(ProductionScheduleService $service)
    {
        $result = $service->getAllOngoingProgress();
        return $this->successResponse($result, 'Ongoing progress retrieved successfully');
    }

    /**
     * Get all ongoing schedules grouped by team (Gantt chart view).
     */
    public function getTeamSchedules(ProductionScheduleService $service)
    {
        $result = $service->getOngoingTeamSchedules();
        return $this->successResponse($result, 'Team schedules retrieved successfully');
    }

    /**
     * Get all schedules for every await_material order in one query, keyed by order ID.
     */
    public function getAwaitMaterialSchedules(ProductionScheduleService $service)
    {
        $result = $service->getSchedulesForAwaitMaterialOrders();
        return $this->successResponse($result, 'Await-material schedules retrieved successfully');
    }

    /**
     * Get summary data for all finished orders with cost analysis.
     */
    public function getFinishedSummary(ProductionScheduleService $service)
    {
        $result = $service->getFinishedOrdersSummary();
        return $this->successResponse($result, 'Finished orders summary retrieved successfully');
    }

    /**
     * Update a schedule record's status.
     */
    public function updateStatus(Request $request, ProductionScheduleService $service, int $id)
    {
        try {
            $validated = $request->validate([
                'status' => ['required', 'string', 'in:completed'],
            ]);

            $result = $service->updateScheduleStatus($id, $validated['status']);
            return $this->successResponse($result, 'Schedule status updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Schedule not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 409);
        }
    }
}
