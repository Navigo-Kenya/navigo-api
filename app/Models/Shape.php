<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Shape extends Model
{
    protected $primaryKey = 'shape_id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    protected $appends = ['points'];
    protected $hidden = ['path'];

    // Converts the PostGIS LineString back into an array of coordinates for Mapbox to draw
    public function getPointsAttribute()
    {
        $geojson = DB::selectOne("SELECT ST_AsGeoJSON(path) as geojson FROM shapes WHERE shape_id = ?", [$this->shape_id])->geojson;

        $geometry = json_decode($geojson, true);
        return $geometry['coordinates'] ?? []; // Returns [[lng, lat], [lng, lat], ...]
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'shape_id', 'shape_id');
    }
}
