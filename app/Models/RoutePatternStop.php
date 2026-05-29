<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutePatternStop extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stop_sequence'     => 'integer',
        'timepoint'         => 'boolean',
        'pickup_type'       => 'integer',
        'drop_off_type'     => 'integer',
        'distance_traveled' => 'float',
    ];

    public function pattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class, 'route_pattern_id', 'id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'stop_id', 'id');
    }
}
