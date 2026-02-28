<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    protected $table = 'material';

    protected $fillable = [
        'kode_material',
        'nama_material',
        'satuan',
        'jumlah',
        'harga',
        'category',
        'status',
    ];

    public function checkLowStock($query, $threshold = 10)
    {
        return $query->where('jumlah', '<', $threshold);
    }

    protected $casts = [
        'jumlah' => 'integer',
        'harga' => 'integer',
    ];
}