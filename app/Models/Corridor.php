<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Corridor extends Model
{
    protected $primaryKey = 'corridor_id';
    protected $keyType    = 'string';
    public $incrementing  = false;
    protected $guarded    = [];
    protected $appends    = ['points'];
    protected $hidden     = ['path'];

    public function getPointsAttribute(): array
    {
        $result = DB::selectOne(
            "SELECT ST_AsGeoJSON(path) as geojson FROM corridors WHERE corridor_id = ?",
            [$this->corridor_id]
        );
        if (!$result || !$result->geojson) {
            return [];
        }
        $geometry = json_decode($result->geojson, true);
        return $geometry['coordinates'] ?? [];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function corridorRoutes(): HasMany
    {
        return $this->hasMany(CorridorRoute::class, 'corridor_id', 'corridor_id');
    }

    public function routes()
    {
        return $this->belongsToMany(Route::class, 'corridor_routes', 'corridor_id', 'route_id', 'corridor_id', 'route_id')
            ->withPivot('direction_id')
            ->withTimestamps();
    }
}
