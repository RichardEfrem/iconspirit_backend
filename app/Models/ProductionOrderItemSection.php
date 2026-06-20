<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrderItemSection extends Model
{
    use HasFactory;

    protected $table = 'production_order_item_section';

    protected $fillable = [
        'production_order_id',
        'name',
    ];

    /**
     * Get the production order that owns this section.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /**
     * Get the items that belong to this section.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class, 'production_order_item_section_id');
    }
}