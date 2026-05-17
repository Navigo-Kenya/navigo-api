<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CachedSnappedPolyline extends Model
{
    protected $fillable = ['cache_key', 'coordinates'];

    protected $casts = ['coordinates' => 'array'];
}
