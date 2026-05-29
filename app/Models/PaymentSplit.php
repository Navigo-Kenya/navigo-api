<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSplit extends Model
{
    protected $fillable = [
        'external_ref', 'amount_total',
        'vehicle_wallet_id', 'sacco_wallet_id', 'platform_wallet_id',
        'vehicle_amount', 'sacco_amount', 'platform_amount',
        'split_config_id', 'route_id', 'vehicle_id', 'status',
    ];

    protected $casts = [
        'amount_total'    => 'float',
        'vehicle_amount'  => 'float',
        'sacco_amount'    => 'float',
        'platform_amount' => 'float',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function splitConfig(): BelongsTo
    {
        return $this->belongsTo(SplitConfig::class);
    }
}
