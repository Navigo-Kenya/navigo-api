<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceAlert extends Model
{
    protected $fillable = [
        'title', 'description', 'severity', 'effect', 'status',
        'affected_type', 'affected_id', 'starts_at', 'ends_at',
        'created_by', 'auto_generated',
    ];

    protected $casts = [
        'starts_at'      => 'datetime',
        'ends_at'        => 'datetime',
        'auto_generated' => 'boolean',
    ];
}
