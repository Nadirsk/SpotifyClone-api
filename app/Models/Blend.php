<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Blend extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'created_by',
        'title',
        'title_is_default',
        'match_score',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'title_is_default' => 'boolean',
            'match_score' => 'integer',
            'tracks_count' => 'integer',
            'total_duration' => 'integer',
            'last_generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<BlendMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(BlendMember::class);
    }

    /** @return HasMany<BlendInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(BlendInvitation::class);
    }

    /** @return BelongsToMany<Song, $this> */
    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'blend_songs')
            ->using(BlendSong::class)
            ->withPivot(['score', 'reason', 'position'])
            ->orderBy('blend_songs.position');
    }

    /**
     * Whether $user is an active member (creator included) of this Blend.
     *
     * Safe whether or not `members` was eager-loaded — mirrors
     * `Playlist::isCollaborator()`.
     */
    public function isMember(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->relationLoaded('members')) {
            return $this->members->contains('user_id', $user->getKey());
        }

        return $this->members()->where('user_id', $user->getKey())->exists();
    }

    public function isCreator(?User $user): bool
    {
        return $user !== null && $user->getKey() === $this->created_by;
    }
}
