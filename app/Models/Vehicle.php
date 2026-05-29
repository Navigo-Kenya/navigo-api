<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'plate', 'agency_id', 'route_id', 'model', 'capacity', 'status', 'notes',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(VehiclePosition::class);
    }
}
