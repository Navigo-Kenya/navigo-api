<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnTimePerformance extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'route_id', 'date', 'total_trips', 'on_time_trips', 'avg_delay_s', 'p90_delay_s', 'computed_at',
    ];

    protected $casts = [
        'date'        => 'date',
        'computed_at' => 'datetime',
    ];
}
