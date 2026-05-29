<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    protected $fillable = [
        'type', 'severity', 'status', 'route_id', 'stop_id', 'vehicle_id',
        'description', 'response_taken', 'resolved_at', 'resolution_time_mins',
        'reported_by', 'created_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
