<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceException extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date'           => 'date',
        'exception_type' => 'integer',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ServiceCalendar::class, 'service_id', 'service_id');
    }
}
