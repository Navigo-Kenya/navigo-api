<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FareRule extends Model
{
    protected $guarded = [];

    public function fareAttribute(): BelongsTo
    {
        return $this->belongsTo(FareAttribute::class, 'fare_id', 'fare_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }
}
