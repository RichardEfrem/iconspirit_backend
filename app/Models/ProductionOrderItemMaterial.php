<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderItemMaterial extends Model
{
    use HasFactory;

    protected $table = 'production_order_item_material';

    protected $fillable = [
        'production_order_item_id',
        'material_id',
        'quantity',
        'cost',
        'is_deducted',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'cost' => 'decimal:2',
        'is_deducted' => 'boolean',
    ];

    /**
     * Get the production order item that owns this material entry.
     */
    public function productionOrderItem(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderItem::class, 'production_order_item_id');
    }

    /**
     * Get the material associated with this entry.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }
}
