<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrderItemImage extends Model
{
    use HasFactory;

    protected $table = 'production_order_item_image';

    protected $fillable = [
        'image_path',
        'production_order_item_id',
    ];

    /**
     * Get the production order item that owns this image.
     */
    public function productionOrderItem(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderItem::class, 'production_order_item_id');
    }
}
