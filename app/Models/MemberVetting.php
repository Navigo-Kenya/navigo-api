<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberVetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'member_id', 'vetted_by', 'decision', 'notes', 'vetted_at',
    ];

    protected $casts = [
        'vetted_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function vetter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vetted_by');
    }
}
