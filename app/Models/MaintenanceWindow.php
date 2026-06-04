<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceWindow extends Model
{
    protected $fillable = [
        'vehicle_id', 'scheduled_from', 'scheduled_to', 'actual_to',
        'service_type', 'garage_name', 'estimated_cost', 'actual_cost',
        'notes', 'created_by',
    ];

    protected $casts = [
        'scheduled_from' => 'datetime',
        'scheduled_to'   => 'datetime',
        'actual_to'      => 'datetime',
        'estimated_cost' => 'float',
        'actual_cost'    => 'float',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
