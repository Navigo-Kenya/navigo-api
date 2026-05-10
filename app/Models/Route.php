<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    protected $primaryKey = 'route_id';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    public function trips()
    {
        return $this->hasMany(Trip::class, 'route_id', 'route_id');
    }
}
