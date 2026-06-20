<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrderItem extends Model
{
    use HasFactory;

    protected $table = 'production_order_item';

    protected $fillable = [
        'production_order_id',
        'production_order_item_section_id',
        'product_id',
        'panjang',
        'tinggi',
        'quantity',
        'keterangan',
        'production_time',
        'production_deadline',
    ];

    protected $casts = [
        'production_deadline' => 'datetime',
    ];

    protected $appends = [
        'area_m2',
        'total_area_m2',
        'estimated_processing_hours',
    ];

    /**
     * Get the production order that owns the item.
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /**
     * Alias for productionOrder() — kept for backward compatibility.
     */
    public function order(): BelongsTo
    {
        return $this->productionOrder();
    }

    /**
     * Get the section associated with the item.
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderItemSection::class, 'production_order_item_section_id');
    }

    /**
     * Get the product associated with the item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Get the materials associated with this order item.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(ProductionOrderItemMaterial::class, 'production_order_item_id');
    }

    /**
     * Get the images associated with this order item.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductionOrderItemImage::class, 'production_order_item_id');
    }

    /**
     * Get the production schedules for this order item.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class, 'production_order_item_id');
    }

    public function getAreaM2Attribute(): float
    {
        $panjang = (float) ($this->panjang ?? 0);
        $tinggi = (float) ($this->tinggi ?? 0);
        $cm2PerM2 = config('production.cm2_per_m2', 10000);

        return ($panjang * $tinggi) / $cm2PerM2;
    }

    public function getTotalAreaM2Attribute(): float
    {
        $quantity = (int) ($this->quantity ?? 0);

        return $this->area_m2 * $quantity;
    }

    public function getEstimatedProcessingHoursAttribute(): float
    {
        $minutesPerM2 = config('production.minutes_per_m2', 300);

        return $this->total_area_m2 * ($minutesPerM2 / 60);
    }
}
