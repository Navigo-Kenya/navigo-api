<?php

namespace App\Models;

use App\Observers\StopObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[ObservedBy([StopObserver::class])]
class Stop extends Model
{
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    // Tell Laravel to automatically append these computed properties to JSON API responses
    protected $appends = ['lat', 'lng'];
    protected $hidden = ['location']; // Hide the raw binary data

    public function getLatAttribute(): float
    {
        if (array_key_exists('lat', $this->attributes)) {
            return $this->attributes['lat'] !== null ? (float) $this->attributes['lat'] : 0.0;
        }
        $result = DB::selectOne("SELECT ST_Y(location::geometry) as lat FROM stops WHERE id = ?", [$this->id]);
        return $result ? (float) $result->lat : 0.0;
    }

    public function getLngAttribute(): float
    {
        if (array_key_exists('lng', $this->attributes)) {
            return $this->attributes['lng'] !== null ? (float) $this->attributes['lng'] : 0.0;
        }
        $result = DB::selectOne("SELECT ST_X(location::geometry) as lng FROM stops WHERE id = ?", [$this->id]);
        return $result ? (float) $result->lng : 0.0;
    }

    public function stopTimes()
    {
        return $this->hasMany(StopTime::class, 'stop_id', 'id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }
}
