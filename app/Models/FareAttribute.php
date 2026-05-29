<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FareAttribute extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price'            => 'float',
        'payment_method'   => 'integer',
        'transfers'        => 'integer',
        'transfer_duration'=> 'integer',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function fareRules(): HasMany
    {
        return $this->hasMany(FareRule::class, 'fare_id', 'fare_id');
    }
}
