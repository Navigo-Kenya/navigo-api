<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioOverride extends Model
{
    protected $guarded = [];
    protected $casts   = ['data' => 'array'];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(NetworkScenario::class, 'scenario_id');
    }
}
