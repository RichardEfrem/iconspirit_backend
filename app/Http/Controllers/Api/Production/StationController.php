<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\StationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StationController extends Controller
{
    public function index(StationService $service)
    {
        return $this->successResponse($service->getAll(), 'Stations retrieved successfully');
    }

    public function show(StationService $service, int $id)
    {
        try {
            return $this->successResponse($service->getById($id), 'Station retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Station not found', 404);
        }
    }

    public function store(Request $request, StationService $service)
    {
        try {
            $result = $service->create($request->all());
            return $this->successResponse($result, 'Station created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function update(Request $request, StationService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->all());
            return $this->successResponse($result, 'Station updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Station not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function destroy(StationService $service, int $id)
    {
        try {
            $service->delete($id);
            return $this->successResponse(null, 'Station deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Station not found', 404);
        }
    }
}