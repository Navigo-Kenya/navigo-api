<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_type', 'entity_id', 'balance', 'currency', 'last_credited_at',
    ];

    protected $casts = [
        'balance'          => 'float',
        'last_credited_at' => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
