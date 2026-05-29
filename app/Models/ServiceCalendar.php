<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCalendar extends Model
{
    protected $primaryKey  = 'service_id';
    public    $keyType     = 'string';
    public    $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'monday'     => 'boolean',
        'tuesday'    => 'boolean',
        'wednesday'  => 'boolean',
        'thursday'   => 'boolean',
        'friday'     => 'boolean',
        'saturday'   => 'boolean',
        'sunday'     => 'boolean',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'service_id', 'service_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ServiceException::class, 'service_id', 'service_id');
    }
}
