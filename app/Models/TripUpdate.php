<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TripUpdate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trip_id', 'stop_id', 'stop_sequence', 'delay_seconds', 'arrival_estimate', 'recorded_at',
    ];

    protected $casts = [
        'arrival_estimate' => 'datetime',
        'recorded_at'      => 'datetime',
    ];
}
