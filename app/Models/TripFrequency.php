<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripFrequency extends Model
{
    protected $guarded = [];

    protected $casts = [
        'headway_secs' => 'integer',
        'exact_times'  => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
