<?php

namespace App\Services\Inventory;

use App\Models\Material;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialService
{
    // public function getAllMaterials($filters)
    // {
    //     return Material::query()
    //         ->when($filters['search'] ?? null, function ($q, $search) {
    //             $q->where(function ($query) use ($search) {
    //                 $query->where('nama_material', 'LIKE', "%{$search}%")
    //                     ->orWhere('kode_material', 'LIKE', "%{$search}%");
    //             });
    //         })
    //         ->when($filters['category'] ?? null, fn($q, $cat) => $q->where('category', $cat))
    //         ->when($filters['status'] ?? null, fn($q, $status) => $q->where('status', $status))
    //         ->paginate($filters['per_page'] ?? 20);
    // }
    public function getAllMaterials($filters)
{
    return Material::query()
        ->when($filters['search'] ?? null, function ($q, $search) {
            $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);
            $q->where(function ($query) use ($escaped) {
                $query->whereRaw('LOWER(nama_material) LIKE ?', ["%".strtolower($escaped)."%"])
                    ->orWhereRaw('LOWER(kode_material) LIKE ?', ["%".strtolower($escaped)."%"]);
            });
        })
        ->when($filters['category'] ?? null, fn($q, $cat) => $q->where('category', $cat))
        ->when($filters['status'] ?? null, function ($q, $status) {
            return match ($status) {
                'LOW_STOCK' => $q->where('jumlah', '>', 0)->where('jumlah', '<=', 10),
                'IN_STOCK' => $q->where('jumlah', '>', 10),
                'OUT_OF_STOCK' => $q->where('jumlah', '<=', 0),
                default => $q,
            };
        })
        ->paginate($filters['per_page'] ?? 20);
}



    public function updateStock(int $id, int $amount): Material
    {
        return DB::transaction(function () use ($id, $amount) {
            $material = Material::query()->lockForUpdate()->findOrFail($id);
            $newStock = $material->jumlah + $amount;

            if ($newStock < 0) {
                throw ValidationException::withMessages([
                    'jumlah' => ['Stock cannot be less than 0.'],
                ]);
            }

            $material->jumlah = $newStock;
            $material->status = $this->calculateStatus($newStock);
            $material->save();

            // Invalidate stock counts cache when stock changes
            Cache::forget('material_stock_counts');

            return $material->fresh();
        });
    }

    public function getStockCounts()
    {
        return Cache::remember('material_stock_counts', 30, function () {
            $counts = Material::query()
                ->selectRaw('SUM(CASE WHEN jumlah > 0 AND jumlah <= 10 THEN 1 ELSE 0 END) AS low_stock')
                ->selectRaw('SUM(CASE WHEN jumlah > 10 THEN 1 ELSE 0 END) AS in_stock')
                ->selectRaw('SUM(CASE WHEN jumlah <= 0 THEN 1 ELSE 0 END) AS out_of_stock')
                ->first();

            return [
                'low_stock' => (int) ($counts->low_stock ?? 0),
                'in_stock' => (int) ($counts->in_stock ?? 0),
                'out_of_stock' => (int) ($counts->out_of_stock ?? 0),
            ];
        });
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
}
