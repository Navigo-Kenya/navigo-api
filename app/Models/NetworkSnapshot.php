<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkSnapshot extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts   = ['snapshot_json' => 'array'];

    public function savedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }

    public function scopeForEntity(Builder $query, string $type, string $id): Builder
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }
}
