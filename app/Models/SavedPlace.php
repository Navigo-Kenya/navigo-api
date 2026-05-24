<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedPlace extends Model
{
    protected $fillable = [
        'user_id', 'name', 'lat', 'lng', 'type', 'place_id',
        'list', 'pin', 'category', 'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
