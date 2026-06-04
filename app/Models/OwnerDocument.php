<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerDocument extends Model
{
    protected $fillable = [
        'owner_id', 'document_type', 'label', 'file_url', 'expiry_date',
    ];

    protected $casts = [
        'expiry_date' => 'date',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(VehicleOwner::class, 'owner_id');
    }
}
