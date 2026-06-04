<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteSla extends Model
{
    protected $table = 'route_sla';

    protected $fillable = [
        'agency_id', 'route_id', 'target_headway_minutes',
        'alert_threshold_minutes', 'active',
    ];

    protected $casts = [
        'target_headway_minutes'  => 'integer',
        'alert_threshold_minutes' => 'integer',
        'active'                  => 'boolean',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }
}
