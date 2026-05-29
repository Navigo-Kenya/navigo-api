<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'wallet_id', 'type', 'amount', 'balance_after',
        'reference', 'payment_id', 'description', 'created_by',
    ];

    protected $casts = [
        'amount'       => 'float',
        'balance_after' => 'float',
        'created_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tx) {
            $tx->created_at = now();
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
