<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    protected $primaryKey  = 'agency_id';
    public    $keyType     = 'string';
    public    $incrementing = false;

    protected $guarded = [];

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class, 'agency_id', 'agency_id');
    }
}
