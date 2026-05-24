<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = ['phone', 'code', 'type', 'attempts', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function scopeValid(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', 3);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }
}
