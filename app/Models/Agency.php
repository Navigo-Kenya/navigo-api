<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    protected $primaryKey  = 'agency_id';
    public    $keyType     = 'string';
    public    $incrementing = false;

    protected $guarded = [];

    public function getLogoUrlAttribute(?string $value): ?string
    {
        if (!$value) return null;
        if (preg_match('#/(storage|uploads)/(.+)$#', $value, $matches)) {
            return url("/{$matches[1]}/{$matches[2]}");
        }
        return $value;
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class, 'agency_id', 'agency_id');
    }

    public function operatedRoutes(): BelongsToMany
    {
        return $this->belongsToMany(Route::class, 'route_operators', 'agency_id', 'route_id')
                    ->withPivot('status', 'linked_at');
    }
}
