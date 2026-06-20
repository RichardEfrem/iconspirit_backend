<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FactoryLocation extends Model
{
    use HasFactory;

    protected $table = 'factory_location';

    protected $fillable = [
        'nama_factory',
        'alamat_factory',
        'priority',
    ];

    protected $casts = [
        'priority' => 'integer',
    ];

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class, 'factory_location_id');
    }

    public function spkAssignments(): HasMany
    {
        return $this->hasMany(Spk::class, 'assigned_factory');
    }
}
