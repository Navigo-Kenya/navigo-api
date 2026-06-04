<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffInvitation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agency_id', 'email', 'role', 'invited_by',
        'token', 'expires_at', 'accepted_at', 'created_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (StaffInvitation $inv) {
            $inv->created_at ??= now();
        });
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && !$this->isExpired();
    }
}
