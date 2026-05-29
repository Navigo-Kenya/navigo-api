<?php

namespace App\Models;

use App\Models\SavedJourney;
use App\Models\SavedPlace;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'phone_verified_at',
        'google_id',
        'apple_id',
        'avatar',
        'oauth_provider',
        'settings',
        'points',
        'role',
        'banned_at',
        'ban_reason',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'phone_verified_at'  => 'datetime',
            'banned_at'          => 'datetime',
            'password'           => 'hashed',
            'settings'           => 'array',
        ];
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'superadmin']);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function hasConsoleAccess(): bool
    {
        return in_array($this->role, ['moderator', 'admin', 'superadmin']);
    }

    public function isPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function savedPlaces(): HasMany
    {
        return $this->hasMany(SavedPlace::class);
    }

    public function savedJourneys(): HasMany
    {
        return $this->hasMany(SavedJourney::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function getAvatarAttribute($value): ?string
    {
        if (!$value) return null;
        // Rebuild with current APP_URL so stored http://localhost URLs work across envs
        if (preg_match('#/storage/(.+)$#', $value, $matches)) {
            return url('/storage/' . $matches[1]);
        }
        return $value;
    }

    public function userBadges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'user_badges')->withPivot('earned_at');
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function level(): int
    {
        return match (true) {
            $this->points >= 4500 => 7,
            $this->points >= 2000 => 6,
            $this->points >= 900  => 5,
            $this->points >= 400  => 4,
            $this->points >= 150  => 3,
            $this->points >= 50   => 2,
            default               => 1,
        };
    }

    public function levelLabel(): string
    {
        $labels = ['Commuter', 'Explorer', 'Safiri Guide', 'Transit Expert', 'Matatu Master', 'Route Pioneer', 'Community Elder'];
        return $labels[$this->level() - 1];
    }

    public function pointsToNextLevel(): int
    {
        $thresholds = [50, 150, 400, 900, 2000, 4500, PHP_INT_MAX];
        return max(0, $thresholds[$this->level() - 1] - $this->points);
    }

    public function nextLevelLabel(): string
    {
        $labels = ['Commuter', 'Explorer', 'Safiri Guide', 'Transit Expert', 'Matatu Master', 'Route Pioneer', 'Community Elder'];
        $next   = min($this->level(), 6);
        return $labels[$next];
    }
}
