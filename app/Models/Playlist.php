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
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visibility' => PlaylistVisibility::class,
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
     * direct link but must not appear in browse or search results.
     *
     * @param  Builder<Playlist>  $query
     * @return Builder<Playlist>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query->where(function (Builder $q) use ($viewer): void {
            $q->where('visibility', PlaylistVisibility::Public);

            if ($viewer !== null) {
                $q->orWhere('user_id', $viewer->getKey());
            }
        });
    }
}
