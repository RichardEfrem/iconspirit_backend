<?php

namespace App\Http\Controllers\Api\Inventory;

use Illuminate\Http\Request;
use App\Models\Material;
use App\Services\Inventory\MaterialService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Inventory\MaterialResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class MaterialController extends Controller

{
    public function index(Request $request, MaterialService $service)
    {
        $materials = $service->getAllMaterials($request->all());
        $resourceCollection = MaterialResource::collection($materials);
        // $stockcounts = $service->getStockCounts();
        return $this->successResponse($resourceCollection, 'Material retrieved successfully');
    }

    public function getStockCounts(MaterialService $service)
    {
        return $this->successResponse($service->getStockCounts(), 'Stock counts retrieved successfully');
    }

    private function calculateStatus(int $jumlah): string
    {
        if ($jumlah <= 0) {
            return 'OUT_OF_STOCK';
        }
        if ($jumlah <= 10) {
            return 'LOW_STOCK';
        }
        return 'IN_STOCK';
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'kode_material' => 'required|unique:material',
            'nama_material' => 'required',
            'satuan' => 'required',
            'jumlah' => 'required|integer|min:0',
            'harga' => 'required|integer|min:0',
            'category' => 'required',
        ]);

        $validated['status'] = $this->calculateStatus($validated['jumlah']);

        try {
            $material = Material::create($validated);
            return $this->successResponse(new MaterialResource($material), 'Material created successfully', 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create material', 500);
        }
    }

    public function updateStock(Request $request, MaterialService $service, $id)
    {
        $validated = $request->validate([
            'jumlah' => 'required|integer',
        ]);

        try {
            $material = $service->updateStock($id, $validated['jumlah']);
            return $this->successResponse(new MaterialResource($material), 'Material stock updated successfully');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Material not found', 404);
        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update material stock', 500);
        }
    }

    public function show($id)
    {
        $material = Material::find($id);

        if (!$material) {
            return $this->errorResponse('Material not found', 404);
        }

        return $this->successResponse(new MaterialResource($material), 'Material retrieved successfully');
    }
}
