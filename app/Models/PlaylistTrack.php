<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Extends `Pivot` (not a plain `Model`) so `Playlist::songs()`/`Song::playlists()`
 * can `->using(PlaylistTrack::class)` — that is what makes the relation hydrate
 * `$song->pivot` as an actual `PlaylistTrack`, with `added_at` cast to Carbon,
 * instead of a generic `Pivot` holding raw column strings.
 */
class PlaylistTrack extends Pivot
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'playlist_id',
        'song_id',
        'position',
        'added_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'added_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Playlist, $this> */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
