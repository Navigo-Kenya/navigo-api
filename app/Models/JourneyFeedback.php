<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyFeedback extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tags'           => 'array',
        'rating'         => 'integer',
        'custom_fare'    => 'integer',
        'estimated_fare' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
