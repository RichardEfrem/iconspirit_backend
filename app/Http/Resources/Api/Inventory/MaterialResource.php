<?php

namespace App\Http\Resources\Api\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
       return [
        'id' => $this->id,
        'kode_material' => $this->kode_material,
        'nama_material' => $this->nama_material,
        'satuan' => $this->satuan,
        'jumlah' => $this->jumlah,
        'category' => $this->category,
        'harga' => $this->harga,
        'status' => $this->status,
    ];
    }
}
