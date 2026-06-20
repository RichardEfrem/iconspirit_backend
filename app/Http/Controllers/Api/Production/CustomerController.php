<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\CustomerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request, CustomerService $service)
    {
        $perPage = $request->query('per_page');

        $result = $service->list($perPage ? (int) $perPage : null);

        return $this->successResponse($result, 'Customers retrieved successfully');
    }

    public function show(CustomerService $service, int $id)
    {
        try {
            return $this->successResponse($service->findById($id), 'Customer retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Customer not found', 404);
        }
    }

    public function store(Request $request, CustomerService $service)
    {
        try {
            $result = $service->create($request->all());

            return $this->successResponse($result, 'Customer created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function update(Request $request, CustomerService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->all());

            return $this->successResponse($result, 'Customer updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Customer not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function destroy(CustomerService $service, int $id)
    {
        try {
            $service->delete($id);

            return $this->successResponse(null, 'Customer deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Customer not found', 404);
        }
    }
}
