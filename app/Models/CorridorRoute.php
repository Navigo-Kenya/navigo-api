<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorridorRoute extends Model
{
    protected $guarded = [];

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(Corridor::class, 'corridor_id', 'corridor_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }
}
