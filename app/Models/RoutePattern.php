<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutePattern extends Model
{
    protected $primaryKey  = 'id';
    public    $keyType     = 'string';
    public    $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'direction_id' => 'integer',
        'is_canonical' => 'boolean',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function patternStops(): HasMany
    {
        return $this->hasMany(RoutePatternStop::class, 'route_pattern_id', 'id')
            ->orderBy('stop_sequence');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_pattern_id', 'id');
    }
}
