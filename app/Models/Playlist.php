<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlaylistVisibility;
use Database\Factories\PlaylistFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Playlist extends Model
{
    /** @use HasFactory<PlaylistFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'description',
        'cover_image',
        'visibility',
        'is_collaborative',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visibility' => PlaylistVisibility::class,
            'is_collaborative' => 'boolean',
            'tracks_count' => 'integer',
            'total_duration' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<PlaylistTrack, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(PlaylistTrack::class)->orderBy('position');
    }

    /** @return HasMany<PlaylistCollaborator, $this> */
    public function collaborators(): HasMany
    {
        return $this->hasMany(PlaylistCollaborator::class);
    }

    /** @return HasOne<PlaylistInvitation, $this> */
    public function invitation(): HasOne
    {
        return $this->hasOne(PlaylistInvitation::class);
    }

    /**
     * Whether $user is an active collaborator on this playlist.
     *
     * Safe to call whether or not `collaborators` was eager-loaded: it reads
     * the loaded collection when present, and otherwise runs one explicit
     * query rather than triggering Eloquent's implicit lazy-load (which
     * `Model::preventLazyLoading()` turns into a 500 outside production).
     */
    public function isCollaborator(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->relationLoaded('collaborators')) {
            return $this->collaborators->contains('user_id', $user->getKey());
        }

        return $this->collaborators()->where('user_id', $user->getKey())->exists();
    }

    /** @return BelongsToMany<Song, $this> */
    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'playlist_tracks')
            ->withPivot(['position', 'added_at'])
            ->orderBy('playlist_tracks.position');
    }

    /**
     * Playlists a given viewer is allowed to see in a listing.
     *
     * Unlisted playlists are deliberately excluded: they are reachable by
     * direct link but must not appear in browse or search results. A private
     * playlist the viewer collaborates on IS included — same as real
     * Spotify, a collaborative playlist you joined shows up in your own
     * library regardless of who owns it.
     *
     * @param  Builder<Playlist>  $query
     * @return Builder<Playlist>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query->where(function (Builder $q) use ($viewer): void {
            $q->where('visibility', PlaylistVisibility::Public);

            if ($viewer !== null) {
                $q->orWhere('user_id', $viewer->getKey())
                    ->orWhereHas('collaborators', function (Builder $collaborators) use ($viewer): void {
                        $collaborators->where('user_id', $viewer->getKey());
                    });
            }
        });
    }
}
