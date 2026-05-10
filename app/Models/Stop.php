<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Stop extends Model
{
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    // Tell Laravel to automatically append these computed properties to JSON API responses
    protected $appends = ['lat', 'lng'];
    protected $hidden = ['location']; // Hide the raw binary data

    public function getLatAttribute()
    {
        $result = DB::selectOne("SELECT ST_Y(location::geometry) as lat FROM stops WHERE id = ?", [$this->id]);
        return $result ? (float) $result->lat : 0.0; // Force float cast!
    }

    public function getLngAttribute()
    {
        $result = DB::selectOne("SELECT ST_X(location::geometry) as lng FROM stops WHERE id = ?", [$this->id]);
        return $result ? (float) $result->lng : 0.0; // Force float cast!
    }

    public function stopTimes()
    {
        return $this->hasMany(StopTime::class, 'stop_id', 'id');
    }
}
