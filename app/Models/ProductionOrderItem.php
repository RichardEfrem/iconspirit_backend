<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderItem extends Model
{
    use HasFactory;

    protected $table = 'production_order_item';

    protected $fillable = [
        'production_order_id',
        'product_id',
        'spesifikasi_produk',
        'panjang',
        'lebar',
        'tinggi',
        'quantity',
        'keterangan',
    ];

    /**
     * Get the production order that owns the item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /**
     * Get the product associated with the item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
