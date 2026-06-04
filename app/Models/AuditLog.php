<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agency_id', 'user_id', 'actor_name', 'action',
        'subject_type', 'subject_id', 'before_json', 'after_json',
        'ip_address', 'created_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json'  => 'array',
        'created_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            $log->created_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
