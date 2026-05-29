<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Badge extends Model
{
    protected $fillable = [
        'slug', 'name', 'description', 'icon', 'color',
        'requirement_type', 'requirement_value', 'requirement_meta', 'points_bonus',
    ];

    protected function casts(): array
    {
        return [
            'requirement_meta' => 'array',
        ];
    }

    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_badges')->withTimestamps();
    }
}
