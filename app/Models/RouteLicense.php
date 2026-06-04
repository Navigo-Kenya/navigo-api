<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteLicense extends Model
{
    protected $fillable = [
        'agency_id', 'route_id', 'license_number',
        'issuing_authority', 'issue_date', 'expiry_date',
        'goodwill_value', 'notes',
    ];

    protected $casts = [
        'issue_date'     => 'date:Y-m-d',
        'expiry_date'    => 'date:Y-m-d',
        'goodwill_value' => 'float',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function getStatusAttribute(): string
    {
        if (!$this->expiry_date) return 'unknown';
        $days = now()->diffInDays($this->expiry_date, false);
        if ($days < 0) return 'expired';
        if ($days <= 30) return 'warning';
        return 'ok';
    }
}
