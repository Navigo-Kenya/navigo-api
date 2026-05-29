<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SplitConfig extends Model
{
    protected $fillable = [
        'agency_id', 'vehicle_pct', 'sacco_pct', 'platform_pct', 'notes', 'is_active',
    ];

    protected $casts = [
        'vehicle_pct'  => 'float',
        'sacco_pct'    => 'float',
        'platform_pct' => 'float',
        'is_active'    => 'boolean',
    ];
}
