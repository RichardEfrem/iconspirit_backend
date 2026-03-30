<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\LocationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FactoryLocationController extends Controller
{
    public function index(LocationService $service)
    {
        return $this->successResponse($service->getAll(), 'Factory locations retrieved successfully');
    }

    public function show(LocationService $service, int $id)
    {
        try {
            return $this->successResponse($service->getById($id), 'Factory location retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Factory location not found', 404);
        }
    }

    public function store(Request $request, LocationService $service)
    {
        try {
            $result = $service->create($request->all());
            return $this->successResponse($result, 'Factory location created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function update(Request $request, LocationService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->all());
            return $this->successResponse($result, 'Factory location updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Factory location not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function destroy(LocationService $service, int $id)
    {
        try {
            $service->delete($id);
            return $this->successResponse(null, 'Factory location deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Factory location not found', 404);
        }
    }
}