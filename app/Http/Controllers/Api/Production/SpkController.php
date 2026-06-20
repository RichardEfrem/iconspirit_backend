<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\SpkService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SpkController extends Controller
{
    public function index(SpkService $service)
    {
        return $this->successResponse($service->getAll(), 'SPK data retrieved successfully');
    }

    public function show(SpkService $service, int $id)
    {
        try {
            return $this->successResponse($service->findById($id), 'SPK data retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('SPK data not found', 404);
        }
    }

    public function store(Request $request, SpkService $service)
    {
        try {
            $result = $service->create(array_merge($request->all(), [
                'created_by' => $request->user()?->id,
            ]));

            return $this->successResponse($result, 'SPK created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function showByProductionOrder(SpkService $service, int $productionOrderId)
    {
        try {
            return $this->successResponse(
                $service->findByProductionOrderId($productionOrderId),
                'SPK data retrieved successfully'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('SPK not found for this production order', 404);
        }
    }
}
