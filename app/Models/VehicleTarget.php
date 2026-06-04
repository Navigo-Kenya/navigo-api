<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleTarget extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agency_id', 'vehicle_id', 'vehicle_class', 'daily_target',
        'effective_from', 'effective_to', 'created_by',
    ];

    protected $casts = [
        'daily_target'   => 'float',
        'effective_from' => 'date:Y-m-d',
        'effective_to'   => 'date:Y-m-d',
        'created_at'     => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
