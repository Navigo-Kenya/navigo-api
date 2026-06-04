<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FleetDevice extends Model
{
    protected $fillable = [
        'vehicle_id', 'device_type', 'brand', 'model', 'imei',
        'protocol', 'ingest_token', 'server_ip', 'server_port',
        'last_seen_at', 'last_ip', 'is_active', 'meta', 'notes', 'added_by',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'meta'         => 'array',
        'last_seen_at' => 'datetime',
        'server_port'  => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (FleetDevice $device) {
            if (empty($device->ingest_token)) {
                $device->ingest_token = Str::random(48);
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function rotateToken(): string
    {
        $token = Str::random(48);
        $this->update(['ingest_token' => $token]);
        return $token;
    }
}
