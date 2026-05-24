<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionVote extends Model
{
    protected $fillable = ['user_id', 'contribution_id', 'vote'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }
}
