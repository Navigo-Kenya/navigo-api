<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Level extends Model
{
    protected $guarded = [];

    protected $casts = [
        'level_index' => 'float',
    ];

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'stop_id', 'id');
    }
}
