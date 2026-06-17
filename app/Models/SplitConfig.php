<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SplitConfig extends Model
{
    protected $fillable = [
        'agency_id',
        'split_enabled',
        'split_type',
        'vehicle_pct',
        'sacco_pct',
        'platform_pct',
        'daily_target',
        'daily_sacco_levy',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'split_enabled'    => 'boolean',
        'vehicle_pct'      => 'float',
        'sacco_pct'        => 'float',
        'platform_pct'     => 'float',
        'daily_target'     => 'float',
        'daily_sacco_levy' => 'float',
        'is_active'        => 'boolean',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function isLengoMode(): bool
    {
        return $this->split_enabled && $this->split_type === 'lengo';
    }

    public function isPercentageMode(): bool
    {
        return $this->split_enabled && $this->split_type === 'percentage';
    }
}
