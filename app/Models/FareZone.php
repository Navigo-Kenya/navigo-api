<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FareZone extends Model
{
    protected $guarded = [];

    protected $appends = ['geojson'];
    protected $hidden  = ['zone_polygon'];

    public function getGeojsonAttribute(): ?array
    {
        if (!$this->id) return null;
        $row = DB::selectOne(
            'SELECT ST_AsGeoJSON(zone_polygon) as g FROM fare_zones WHERE id = ?',
            [$this->id]
        );
        return $row ? json_decode($row->g, true) : null;
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function fareRules(): HasMany
    {
        return $this->hasMany(FareRule::class, 'origin_id', 'zone_id');
    }
}
