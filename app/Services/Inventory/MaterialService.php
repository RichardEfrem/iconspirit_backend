<?php

namespace App\Services\Inventory;

use App\Models\Material;

class MaterialService
{
    public function getAllMaterials($filters)
    {
        return Material::query()
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('nama_material', 'LIKE', "%{$search}%")
                        ->orWhere('kode_material', 'LIKE', "%{$search}%");
                });
            })
            ->when($filters['category'] ?? null, fn($q, $cat) => $q->where('category', $cat))
            ->when($filters['status'] ?? null, fn($q, $status) => $q->where('status', $status))
            ->paginate(20);
    }

    public function updateStock(int $id, int $amount, string $status)
    {
        $material = Material::findOrFail($id);
        $material->increment('jumlah', $amount);
        $material->update(['status' => $status]);
        return $material;
    }
}
