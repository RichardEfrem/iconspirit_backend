<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrder extends Model
{
    use HasFactory;

    protected $table = 'production_order';

    protected $fillable = [
        'kode_spk',
        'nama_customer',
        'alamat_customer',
        'tanggal_order',
        'status_id',
    ];

    /**
     * Get the items that belong to the production order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class, 'production_order_id');
    }

    /**
     * Get the status associated with the production order.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProductionStatus::class, 'status_id');
    }
}
