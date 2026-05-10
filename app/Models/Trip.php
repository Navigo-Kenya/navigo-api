<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    protected $primaryKey = 'trip_id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    public function route()
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function shape()
    {
        return $this->belongsTo(Shape::class, 'shape_id', 'shape_id');
    }

    public function stopTimes()
    {
        return $this->hasMany(StopTime::class, 'trip_id', 'trip_id');
    }
}
