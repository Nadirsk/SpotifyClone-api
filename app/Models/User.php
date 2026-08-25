<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AudioQuality;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'avatar',
        'country',
        'language',
        // A *preference*, clamped to the plan's ceiling at read time rather
        // than on write — see SubscriptionService::effectiveQualityFor().
        'audio_quality',
        'offline_enabled',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'audio_quality' => AudioQuality::class,
            'offline_enabled' => 'boolean',
        ];
    }

    /**
     * Whether this account can reach the admin panel's `admin/*` API.
     *
     * `role` is deliberately absent from `$fillable` — it must only ever be
     * set with `forceFill()` from a seeder or console command, never through
     * a public request payload like `/auth/register`.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Every subscription this account has ever held, newest first — a history,
     * not a single current row. `SubscriptionService` is what interprets it;
     * nothing should read this relation to decide entitlement.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->orderByDesc('created_at');
    }

    /** @return HasMany<Playlist, $this> */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    /**
     * Playlists this user was invited to collaborate on — never ones they own.
     * `Playlist::scopeVisibleTo()` is the read path a page actually uses; this
     * exists for the inverse lookup (e.g. cleanup, admin tooling).
     *
     * @return BelongsToMany<Playlist, $this>
     */
    public function collaboratingPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'playlist_collaborators')
            ->withTimestamps();
    }

    /** @return HasMany<Favorite, $this> */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /** @return HasMany<History, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(History::class);
    }

    /** @return HasMany<SearchHistory, $this> */
    public function searchHistory(): HasMany
    {
        return $this->hasMany(SearchHistory::class);
    }

    /** @return HasMany<OauthAccount, $this> */
    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OauthAccount::class);
    }

    /** @return HasMany<EmailLoginCode, $this> */
    public function emailLoginCodes(): HasMany
    {
        return $this->hasMany(EmailLoginCode::class);
    }

    /**
     * People who follow this user. `user_follows` is not a simple pivot —
     * `follower_id`/`followed_id` are two different roles on the same table —
     * so this and `following()` model the same table as two distinct
     * relations rather than one ambiguous "users" relation.
     *
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_follows', 'followed_id', 'follower_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_follows', 'follower_id', 'followed_id')
            ->withTimestamps();
    }

    /** Blends this user created. @return HasMany<Blend, $this> */
    public function createdBlends(): HasMany
    {
        return $this->hasMany(Blend::class, 'created_by');
    }

    /** Every Blend this user is a member of, creator included. @return BelongsToMany<Blend, $this> */
    public function blends(): BelongsToMany
    {
        return $this->belongsToMany(Blend::class, 'blend_members')
            ->withPivot('role')
            ->withTimestamps();
    }
}
