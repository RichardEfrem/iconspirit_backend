<?php

namespace App\Http\Controllers\Api\Production;

use App\Http\Controllers\Controller;
use App\Services\Production\ProductService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
	public function index(ProductService $service)
	{
		return $this->successResponse($service->getAll(), 'Products retrieved successfully');
	}

	public function show(ProductService $service, int $id)
	{
		try {
			return $this->successResponse($service->getById($id), 'Product retrieved successfully');
		} catch (ModelNotFoundException $e) {
			return $this->errorResponse('Product not found', 404);
		}
	}

	public function store(Request $request, ProductService $service)
	{
		try {
			$result = $service->create($request->all());
			return $this->successResponse($result, 'Product created successfully', 201);
		} catch (ValidationException $e) {
			return $this->errorResponse('Validation failed', 422, $e->errors());
		}
	}

	public function update(Request $request, ProductService $service, int $id)
	{
		try {
			$result = $service->update($id, $request->all());
			return $this->successResponse($result, 'Product updated successfully');
		} catch (ModelNotFoundException $e) {
			return $this->errorResponse('Product not found', 404);
		} catch (ValidationException $e) {
			return $this->errorResponse('Validation failed', 422, $e->errors());
		}
	}

	public function destroy(ProductService $service, int $id)
	{
		try {
			$service->delete($id);
			return $this->successResponse(null, 'Product deleted successfully');
		} catch (ModelNotFoundException $e) {
			return $this->errorResponse('Product not found', 404);
		}
	}
}


