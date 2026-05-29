<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class InteropEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'connections' => 'array',
    ];

    protected $appends = ['lat', 'lng'];
    protected $hidden  = ['location'];

    public function getLatAttribute(): float
    {
        if (array_key_exists('lat', $this->attributes)) {
            return (float) $this->attributes['lat'];
        }
        $row = DB::selectOne('SELECT ST_Y(location::geometry) as lat FROM interop_entries WHERE id = ?', [$this->id]);
        return $row ? (float) $row->lat : 0.0;
    }

    public function getLngAttribute(): float
    {
        if (array_key_exists('lng', $this->attributes)) {
            return (float) $this->attributes['lng'];
        }
        $row = DB::selectOne('SELECT ST_X(location::geometry) as lng FROM interop_entries WHERE id = ?', [$this->id]);
        return $row ? (float) $row->lng : 0.0;
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'gtfs_stop_id', 'id');
    }
}
