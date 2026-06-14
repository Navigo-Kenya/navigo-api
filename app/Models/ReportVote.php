<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportVote extends Model
{
    protected $fillable = ['report_id', 'user_id', 'ip_hash', 'vote'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(TransitReport::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
