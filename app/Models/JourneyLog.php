<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JourneyLog extends Model
{
    protected $fillable = [
        'user_id',
        'origin_name',
        'destination_name',
        'primary_route',
        'type',
    ];
}
