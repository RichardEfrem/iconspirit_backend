<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationTransitTime extends Model
{
    use HasFactory;

    protected $table = 'location_transit_time';

    protected $fillable = [
        'origin_factory_id',
        'destination_factory_id',
        'transit_time',
    ];

    protected $casts = [
        'origin_factory_id' => 'integer',
        'destination_factory_id' => 'integer',
        'transit_time' => 'integer',
    ];

    public function originFactory(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, 'origin_factory_id');
    }

    public function destinationFactory(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, 'destination_factory_id');
    }
}
