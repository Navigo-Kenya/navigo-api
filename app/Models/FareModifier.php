<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FareModifier extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('fare:modifiers:active');
        static::saved($flush);
        static::deleted($flush);
    }

    protected $casts = [
        'multiplier'      => 'float',
        'fixed_surcharge' => 'float',
        'condition_data'  => 'array',
        'is_active'       => 'boolean',
        'start_at'        => 'datetime',
        'end_at'          => 'datetime',
    ];

    /**
     * Whether this modifier is currently in its valid time window (if one is set).
     */
    public function isInWindow(): bool
    {
        $now = now();
        if ($this->start_at && $now->lt($this->start_at)) return false;
        if ($this->end_at   && $now->gt($this->end_at))   return false;
        return true;
    }

    /**
     * Returns true when is_active=true AND within the optional time window.
     */
    public function isEffective(): bool
    {
        return $this->is_active && $this->isInWindow();
    }
}
