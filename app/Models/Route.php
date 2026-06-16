<?php

namespace App\Models;

use App\Observers\RouteObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([RouteObserver::class])]
class Route extends Model
{
    protected $primaryKey = 'route_id';
    protected $keyType    = 'string';
    public $incrementing  = false;
    protected $guarded    = [];

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id', 'route_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function patterns(): HasMany
    {
        return $this->hasMany(RoutePattern::class, 'route_id', 'route_id');
    }

    public function operatorAgencies(): BelongsToMany
    {
        return $this->belongsToMany(Agency::class, 'route_operators', 'route_id', 'agency_id')
                    ->withPivot('status', 'linked_at');
    }
}
