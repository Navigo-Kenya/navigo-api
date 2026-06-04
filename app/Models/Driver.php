<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Driver extends Model
{
    protected $fillable = [
        'name', 'phone', 'license_no', 'vehicle_id', 'status', 'notes',
        'psv_badge_expiry', 'licence_expiry', 'good_conduct_expiry', 'medical_cert_expiry',
    ];

    protected $casts = [
        'psv_badge_expiry'   => 'date',
        'licence_expiry'     => 'date',
        'good_conduct_expiry' => 'date',
        'medical_cert_expiry' => 'date',
    ];

    public function complianceStatus(): string
    {
        $fields = ['psv_badge_expiry', 'licence_expiry', 'good_conduct_expiry', 'medical_cert_expiry'];

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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
