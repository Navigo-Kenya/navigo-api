<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedJourney extends Model
{
    protected $fillable = [
        'user_id', 'label',
        'from_name', 'from_lat', 'from_lng', 'from_id', 'from_type',
        'to_name', 'to_lat', 'to_lng', 'to_id', 'to_type',
        'summary', 'duration', 'route',
    ];

    protected $casts = [
        'route' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
