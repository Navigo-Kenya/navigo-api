<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageQueue extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agency_id', 'route_id', 'vehicle_id',
        'queue_position', 'status', 'departed_at', 'created_by',
    ];

    protected $casts = [
        'queued_at'   => 'datetime',
        'departed_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }
}
