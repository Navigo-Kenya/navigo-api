<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conductor extends Model
{
    protected $fillable = [
        'agency_id', 'name', 'phone', 'psv_badge_no', 'psv_badge_expiry',
        'status', 'notes',
    ];

    protected $casts = [
        'psv_badge_expiry' => 'date:Y-m-d',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }
}
