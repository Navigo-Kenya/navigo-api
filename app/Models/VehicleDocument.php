<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDocument extends Model
{
    protected $fillable = [
        'vehicle_id', 'document_type', 'label',
        'file_url', 'file_name', 'mime_type', 'file_size',
        'expiry_date', 'uploaded_by',
    ];

    protected $casts = [
        'expiry_date' => 'date:Y-m-d',
        'file_size'   => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
