<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CachedWalkingRoute extends Model
{
    protected $fillable = [
        'from_lat', 'from_lng',
        'to_lat',   'to_lng',
        'coordinates',
        'walk_steps',
        'distance_m',
        'duration_s',
    ];

    protected $casts = [
        'coordinates' => 'array',
        'walk_steps'  => 'array',
        'from_lat'    => 'float',
        'from_lng'    => 'float',
        'to_lat'      => 'float',
        'to_lng'      => 'float',
    ];
}
