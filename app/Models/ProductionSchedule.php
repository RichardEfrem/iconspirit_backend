<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionSchedule extends Model
{
    use HasFactory;

    protected $table = 'production_schedules';

    protected $fillable = [
        'production_order_item_id',
        'station_id',
        'team_id',
        'start_time',
        'end_time',
        'status',
        'actual_start',
        'actual_end',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(ProductionOrderItem::class, 'production_order_item_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
