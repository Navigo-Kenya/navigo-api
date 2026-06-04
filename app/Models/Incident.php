<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    protected $fillable = [
        'type', 'severity', 'status', 'route_id', 'stop_id', 'vehicle_id',
        'description', 'response_taken', 'resolved_at', 'resolution_time_mins',
        'reported_by', 'created_by', 'resolved_by',
        'assigned_to', 'escalation_level', 'last_escalated_at', 'sla_deadline',
    ];

    protected $casts = [
        'resolved_at'      => 'datetime',
        'last_escalated_at' => 'datetime',
        'sla_deadline'     => 'datetime',
        'escalation_level' => 'integer',
    ];

    // SLA minutes per severity
    public const SLA_MINUTES = [
        'critical' => 60,
        'high'     => 240,
        'medium'   => 480,
        'low'      => 1440,
    ];

    public function getSlaStatusAttribute(): string
    {
        if (!$this->sla_deadline) return 'none';
        if ($this->status === 'resolved') return 'resolved';
        if (now()->gt($this->sla_deadline)) return 'breached';
        if (now()->diffInMinutes($this->sla_deadline, false) <= 30) return 'warning';
        return 'ok';
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
