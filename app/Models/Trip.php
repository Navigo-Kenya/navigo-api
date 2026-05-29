<?php

namespace App\Models;

use App\Observers\TripObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([TripObserver::class])]
class Trip extends Model
{
    protected $primaryKey = 'trip_id';
    protected $keyType    = 'string';
    public $incrementing  = false;
    protected $guarded    = [];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function shape(): BelongsTo
    {
        return $this->belongsTo(Shape::class, 'shape_id', 'shape_id');
    }

    public function stopTimes(): HasMany
    {
        return $this->hasMany(StopTime::class, 'trip_id', 'trip_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ServiceCalendar::class, 'service_id', 'service_id');
    }

    public function routePattern(): BelongsTo
    {
        return $this->belongsTo(RoutePattern::class, 'route_pattern_id', 'id');
    }

    public function frequencies(): HasMany
    {
        return $this->hasMany(TripFrequency::class, 'trip_id', 'trip_id');
    }
}
