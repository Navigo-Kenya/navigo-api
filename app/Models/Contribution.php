<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Contribution extends Model
{
    protected $fillable = [
        'user_id', 'stop_id', 'type', 'title', 'description',
        'data', 'status', 'points_awarded', 'expires_at', 'reviewed_at',
        'reviewed_by', 'decline_reason',
    ];

    protected function casts(): array
    {
        return [
            'data'        => 'array',
            'expires_at'  => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ContributionVote::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'rejected')
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    public function scopeNearby(Builder $query, float $lat, float $lng, int $radiusMeters = 5000): Builder
    {
        return $query->whereHas('stop', function ($q) use ($lat, $lng, $radiusMeters) {
            $q->whereRaw(
                "ST_DWithin(location::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
                [$lng, $lat, $radiusMeters]
            );
        });
    }
}
