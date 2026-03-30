<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\LocationTransitTimeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LocationTransitTimeController extends Controller
{
    public function index(LocationTransitTimeService $service)
    {
        return $this->successResponse($service->getAll(), 'Transit times retrieved successfully');
    }

    public function show(LocationTransitTimeService $service, int $id)
    {
        try {
            return $this->successResponse($service->getById($id), 'Transit time retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Transit time not found', 404);
        }
    }

    public function store(Request $request, LocationTransitTimeService $service)
    {
        try {
            $result = $service->create($request->all());
            return $this->successResponse($result, 'Transit time created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function update(Request $request, LocationTransitTimeService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->all());
            return $this->successResponse($result, 'Transit time updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Transit time not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function destroy(LocationTransitTimeService $service, int $id)
    {
        try {
            $service->delete($id);
            return $this->successResponse(null, 'Transit time deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Transit time not found', 404);
        }
    }
}