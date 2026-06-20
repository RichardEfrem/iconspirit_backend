<?php

namespace App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductionOrder extends Model
{
    use HasFactory;

    protected $table = 'production_order';

    protected $fillable = [
        'customer_id',
        'order_id',
        'nama_customer',
        'alamat_customer',
        'tanggal_order',
        'status_id',
        'is_urgent',
        'production_deadline',
        'production_start',
        'estimated_end',
        'material_eta',
    ];

    protected $casts = [
        'tanggal_order' => 'date:Y-m-d',
        'production_deadline' => 'date:Y-m-d',
        'production_start' => 'datetime',
        'estimated_end' => 'datetime',
        'material_eta' => 'datetime',
    ];

    /**
     * Get the items that belong to the production order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductionOrderItem::class, 'production_order_id');
    }

    /**
     * Get the customer associated with the production order.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get the item sections that belong to the production order.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(ProductionOrderItemSection::class, 'production_order_id');
    }

    /**
     * Get the status associated with the production order.
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(ProductionStatus::class, 'status_id');
    }

    /**
     * Get the SPK associated with the production order.
     */
    public function spk(): HasOne
    {
        return $this->hasOne(Spk::class, 'production_order_id');
    }
}
