<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoMember extends Model
{
    protected $fillable = [
        'agency_id', 'membership_no', 'membership_class', 'status',
        'name', 'phone', 'email', 'national_id', 'kra_pin', 'm_pesa_number',
        'vehicle_owner_id', 'voting_rights', 'share_capital_paid',
        'notes', 'joined_at', 'created_by',
    ];

    protected $casts = [
        'joined_at'          => 'date',
        'voting_rights'      => 'boolean',
        'share_capital_paid' => 'decimal:2',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function vehicleOwner(): BelongsTo
    {
        return $this->belongsTo(VehicleOwner::class, 'vehicle_owner_id');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(MemberFee::class, 'member_id');
    }

    public function vettings(): HasMany
    {
        return $this->hasMany(MemberVetting::class, 'member_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateMembershipNo(string $agencyId): string
    {
        $count = static::where('agency_id', $agencyId)->count();
        return strtoupper($agencyId) . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPendingVetting(): bool
    {
        return $this->status === 'pending_vetting';
    }

    public function totalFeesPaid(): float
    {
        return (float) $this->fees()->sum('amount');
    }
}
