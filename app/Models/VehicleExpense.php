<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleExpense extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agency_id', 'vehicle_id', 'expense_type', 'amount',
        'litres', 'odometer_km', 'description', 'receipt_ref',
        'expense_date', 'recorded_by',
    ];

    protected $casts = [
        'amount'       => 'float',
        'litres'       => 'float',
        'expense_date' => 'date:Y-m-d',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
