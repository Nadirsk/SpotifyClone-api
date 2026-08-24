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

    /*
     | Without this, `AsPivot::getTable()` falls back to its own default —
     | `Str::singular(class_basename($this))`, i.e. `playlist_track` — which is
     | a different convention from a plain Model's (which would have correctly
     | pluralized to `playlist_tracks`, the table the migration actually
     | creates). Every direct `PlaylistTrack::query()` call (adding/removing a
     | song from a playlist, `PlaylistSyncService`'s track reconciliation) hit
     | "Base table or view not found: playlist_track" until this was set.
     | Relation-driven pivot access (`Playlist::songs()->attach()` etc.) never
     | hit it — `belongsToMany()` passes `playlist_tracks` explicitly and that
     | wins regardless of this class's own default.
     */
    protected $table = 'playlist_tracks';

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
