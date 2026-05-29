<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkScenario extends Model
{
    protected $guarded = [];
    protected $casts   = ['published_at' => 'datetime'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(ScenarioOverride::class, 'scenario_id');
    }
}
