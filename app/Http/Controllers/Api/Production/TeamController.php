<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\TeamService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function index(TeamService $service)
    {
        return $this->successResponse($service->getAll(), 'Teams retrieved successfully');
    }

    public function show(TeamService $service, int $id)
    {
        try {
            return $this->successResponse($service->getById($id), 'Team retrieved successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Team not found', 404);
        }
    }

    public function store(Request $request, TeamService $service)
    {
        try {
            $result = $service->create($request->all());
            return $this->successResponse($result, 'Team created successfully', 201);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function update(Request $request, TeamService $service, int $id)
    {
        try {
            $result = $service->update($id, $request->all());
            return $this->successResponse($result, 'Team updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Team not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        }
    }

    public function destroy(TeamService $service, int $id)
    {
        try {
            $service->delete($id);
            return $this->successResponse(null, 'Team deleted successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Team not found', 404);
        }
    }
}