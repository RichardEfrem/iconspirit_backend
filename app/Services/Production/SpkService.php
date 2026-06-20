<?php

namespace App\Services\Production;

use App\Models\ProductionOrder;
use App\Models\Spk;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SpkService
{
    /**
     * Retrieve all SPK records.
     */
    public function getAll(): Collection
    {
        return Spk::with(['productionOrder', 'assignedFactory', 'createdByUser'])->latest()->get();
    }

    /**
     * Retrieve one SPK by ID.
     * @throws ModelNotFoundException
     */
    public function findById(int $id): Spk
    {
        return Spk::with(['productionOrder', 'assignedFactory', 'createdByUser'])->findOrFail($id);
    }

    /**
     * Retrieve one SPK by production order ID.
     * @throws ModelNotFoundException
     */
    public function findByProductionOrderId(int $productionOrderId): Spk
    {
        return Spk::with(['productionOrder', 'assignedFactory', 'createdByUser'])
            ->where('production_order_id', $productionOrderId)
            ->firstOrFail();
    }

    /**
     * Create a new SPK record.
     * @throws ValidationException
     */
    public function create(array $data): Spk
    {
        $validated = Validator::make($data, [
            'production_order_id' => ['required', 'integer', 'exists:production_order,id', 'unique:spk,production_order_id'],
            'tanggal_terbit' => ['required', 'date'],
            'assigned_factory' => ['nullable', 'integer', 'exists:factory_location,id'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        $tanggalTerbit = Carbon::parse($validated['tanggal_terbit'])->toDateString();
        $nomorSpk = $this->generateUniqueNomorSpk();

        return Spk::create([
            'production_order_id' => $validated['production_order_id'],
            'tanggal_terbit' => $tanggalTerbit,
            'nomor_spk' => Str::upper($nomorSpk),
            'assigned_factory' => $validated['assigned_factory'] ?? null,
            'created_by' => $validated['created_by'] ?? null,
        ])->load(['productionOrder', 'assignedFactory', 'createdByUser']);
    }

    /**
     * Create SPK for a production order if it does not exist.
     */
    public function createForProductionOrder(
        ProductionOrder $order,
        ?string $tanggalTerbit = null,
        ?int $assignedFactoryId = null
    ): Spk
    {
        if ($order->spk()->exists()) {
            return $order->spk()->first();
        }

        return $this->create([
            'production_order_id' => $order->id,
            'tanggal_terbit' => Carbon::parse($tanggalTerbit ?? now()->toDateString())->toDateString(),
            'assigned_factory' => $assignedFactoryId,
        ]);
    }

    private function generateUniqueNomorSpk(): string
    {
        do {
            $nomorSpk = Str::upper(Str::random(8));
        } while (Spk::where('nomor_spk', $nomorSpk)->exists());

        return $nomorSpk;
    }
}
