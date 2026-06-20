<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Spk extends Model
{
    use HasFactory;

    protected $table = 'spk';

    protected $fillable = [
        'nomor_spk',
        'tanggal_terbit',
        'production_order_id',
        'assigned_factory',
        'created_by',
    ];

    /**
     * Get the production order that owns this SPK.
     */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /**
     * Get the assigned factory for this SPK.
     */
    public function assignedFactory(): BelongsTo
    {
        return $this->belongsTo(FactoryLocation::class, 'assigned_factory');
    }

    /**
     * Get the user who created this SPK.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
