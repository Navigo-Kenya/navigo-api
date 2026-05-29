<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pathway extends Model
{
    protected $guarded = [];

    protected $casts = [
        'pathway_mode'     => 'integer',
        'is_bidirectional' => 'boolean',
        'length'           => 'float',
        'traversal_time'   => 'integer',
        'stair_count'      => 'integer',
        'max_slope'        => 'float',
        'min_width'        => 'float',
    ];

    public function fromStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'from_stop_id', 'id');
    }

    public function toStop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'to_stop_id', 'id');
    }
}
