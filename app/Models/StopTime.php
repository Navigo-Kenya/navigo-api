<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StopTime extends Model
{
    protected $table = 'stop_times';
    protected $guarded = [];

    public function trip()
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function stop()
    {
        return $this->belongsTo(Stop::class, 'stop_id', 'id');
    }
}
