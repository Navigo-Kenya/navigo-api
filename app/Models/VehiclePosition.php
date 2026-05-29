<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehiclePosition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vehicle_id', 'trip_id', 'lat', 'lng', 'bearing', 'speed_kmh', 'recorded_at',
    ];

    protected $casts = [
        'lat'         => 'float',
        'lng'         => 'float',
        'speed_kmh'   => 'float',
        'recorded_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
