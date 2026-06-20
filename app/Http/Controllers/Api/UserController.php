<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request, UserService $service)
    {
        $perPage = $request->query('per_page');
        $search = $request->query('search');
        $role = $request->query('role');
        $status = $request->query('status');

        $result = $service->list(
            $perPage ? (int) $perPage : null,
            $search ?: null,
            $role ?: null,
            $status ?: null,
        );

        return $this->successResponse($result, 'Users retrieved successfully');
    }

    public function show(UserService $service, int $id)
    {
        try {
            return $this->successResponse($service->findById($id), 'User retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('User not found', 404);
        }
    }

    public function store(Request $request, UserService $service)
    {
        try {
            $result = $service->create($request->all());

            return $this->successResponse($result, 'User created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function update(Request $request, UserService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->all(), $request->user()?->id);

            return $this->successResponse($result, 'User updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('User not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function updateStatus(Request $request, UserService $service, int $id)
    {
        try {
            $status = (string) $request->input('status', '');
            $result = $service->setStatus($id, $status, $request->user()?->id);

            return $this->successResponse($result, 'User status updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('User not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function destroy(Request $request, UserService $service, int $id)
    {
        try {
            $service->delete($id, $request->user()?->id);

            return $this->successResponse(null, 'User deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('User not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }
}
