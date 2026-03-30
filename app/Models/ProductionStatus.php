<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionStatus extends Model
{
    use HasFactory;

    protected $table = 'production_statuses';

    // The primary key is a string (not an auto-incrementing integer)
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'label',
        'color',
    ];

    /**
     * Get the production orders associated with the status.
     */
    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class, 'status_id');
    }
}
