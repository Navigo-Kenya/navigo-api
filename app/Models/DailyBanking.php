<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBanking extends Model
{
    protected $table = 'daily_banking';

    protected $fillable = [
        'agency_id', 'vehicle_id', 'shift_id', 'banking_date',
        'expected_amount', 'banked_amount', 'm_pesa_ref', 'recorded_by', 'notes',
    ];

    protected $casts = [
        'banking_date'    => 'date:Y-m-d',
        'expected_amount' => 'float',
        'banked_amount'   => 'float',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getVarianceAttribute(): float|null
    {
        if ($this->expected_amount === null) return null;
        return $this->banked_amount - $this->expected_amount;
    }
}
