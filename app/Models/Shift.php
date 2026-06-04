<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $fillable = [
        'agency_id', 'vehicle_id', 'driver_id', 'conductor_id',
        'shift_date', 'start_time', 'end_time', 'status',
        'actual_start_time', 'actual_end_time', 'banked_amount',
        'notes', 'created_by',
    ];

    protected $casts = [
        'shift_date'        => 'date:Y-m-d',
        'actual_start_time' => 'datetime',
        'actual_end_time'   => 'datetime',
        'banked_amount'     => 'float',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Conductor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
