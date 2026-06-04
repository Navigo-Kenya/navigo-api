<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleOwner extends Model
{
    protected $fillable = [
        'agency_id', 'name', 'phone', 'email',
        'national_id', 'm_pesa_number', 'notes', 'photo_url',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OwnerDocument::class, 'owner_id');
    }
}
