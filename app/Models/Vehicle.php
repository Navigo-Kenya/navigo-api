<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'plate', 'agency_id', 'route_id', 'owner_id', 'model', 'capacity', 'status', 'notes',
        'insurance_expiry', 'inspection_due', 'road_service_license_expiry', 'speed_limiter_cert_expiry',
    ];

    protected $casts = [
        'insurance_expiry'             => 'date',
        'inspection_due'               => 'date',
        'road_service_license_expiry'  => 'date',
        'speed_limiter_cert_expiry'    => 'date',
    ];

    public function complianceStatus(): string
    {
        $fields = [
            'insurance_expiry', 'inspection_due',
            'road_service_license_expiry', 'speed_limiter_cert_expiry',
        ];

        $expired = false;
        $warning = false;

        foreach ($fields as $field) {
            if ($this->$field === null) continue;
            $daysLeft = now()->startOfDay()->diffInDays($this->$field, false);
            if ($daysLeft < 0)  { $expired = true; break; }
            if ($daysLeft <= 30) { $warning = true; }
        }

        return $expired ? 'expired' : ($warning ? 'warning' : 'ok');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(VehicleOwner::class, 'owner_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(VehiclePosition::class);
    }
}
