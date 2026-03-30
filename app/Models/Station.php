<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    use HasFactory;

    protected $table = 'station';

    protected $fillable = [
        'nama_station',
        'factory_location_id',
        'biaya_harian',
    ];

    protected $casts = [
        'factory_location_id' => 'integer',
        'biaya_harian' => 'integer',
    ];

    public function factoryLocation(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, 'factory_location_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'station_id');
    }
}
