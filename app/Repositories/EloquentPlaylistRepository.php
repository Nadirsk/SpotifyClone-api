<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\PlaylistRepository;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentPlaylistRepository implements PlaylistRepository
{
    public function paginateVisibleTo(?User $viewer, int $page, int $limit): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Playlist> */
        return $this->baseQuery()
            ->visibleTo($viewer)
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(perPage: $limit, page: $page);
    }

    public function paginateForOwner(User $owner, int $page, int $limit): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Playlist> */
        return $this->baseQuery()
            ->where('user_id', $owner->getKey())
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(perPage: $limit, page: $page);
    }

    public function findOrFail(string $id): Playlist
    {
        /** @var Playlist */
        return $this->baseQuery()->findOrFail($id);
    }

    public function create(User $owner, array $attributes): Playlist
    {
        /** @var Playlist $playlist */
        $playlist = $owner->playlists()->create($attributes);

        /*
         | tracks_count/total_duration are NOT NULL DEFAULT 0 columns but are
         | deliberately outside $fillable, so create() never assigns them on
         | the in-memory model — MySQL applies the default, but this object
         | just never learned it, and PlaylistResource would render null
         | instead of 0 for a brand new, trackless playlist.
         */
        $playlist->setRawAttributes(array_merge($playlist->getAttributes(), [
            'tracks_count' => 0,
            'total_duration' => 0,
        ]));

        // The owner is already in hand; setting it saves the Resource a query.
        $playlist->setRelation('owner', $owner);

        return $playlist;
    }

    public function update(Playlist $playlist, array $attributes): Playlist
    {
        $playlist->fill($attributes)->save();

        return $playlist->loadMissing('owner');
    }

    public function delete(Playlist $playlist): void
    {
        $playlist->delete();
    }

    public function addSong(Playlist $playlist, Song $song): bool
    {
        return DB::transaction(function () use ($playlist, $song): bool {
            /*
             | Checked rather than caught: the unique index on
             | (playlist_id, song_id) is the backstop, but a duplicate is a
             | routine 409 and should not surface as a QueryException.
             */
            if ($this->trackQuery($playlist, $song)->exists()) {
                return false;
            }

            $lastPosition = (int) PlaylistTrack::query()
                ->where('playlist_id', $playlist->getKey())
                ->max('position');

            PlaylistTrack::query()->create([
                'playlist_id' => $playlist->getKey(),
                'song_id' => $song->getKey(),
                'position' => $lastPosition + 1,
                'added_at' => now(),
            ]);

            $this->refreshCounters($playlist);

            return true;
        });
    }

    public function removeSong(Playlist $playlist, Song $song): bool
    {
        return DB::transaction(function () use ($playlist, $song): bool {
            // Positions are left with a gap: they only ever define an ordering,
            // so renumbering every following row would be write amplification.
            if ($this->trackQuery($playlist, $song)->delete() === 0) {
                return false;
            }

            $this->refreshCounters($playlist);

            return true;
        });
    }

    public function refreshCounters(Playlist $playlist): void
    {
        $totals = DB::table('playlist_tracks')
            ->join('songs', 'songs.id', '=', 'playlist_tracks.song_id')
            ->where('playlist_tracks.playlist_id', $playlist->getKey())
            // A soft-deleted song still has a pivot row but must not be counted.
            ->whereNull('songs.deleted_at')
            ->selectRaw('COUNT(*) as tracks, COALESCE(SUM(songs.duration), 0) as duration')
            ->first();

        // Both columns are denormalized counters, deliberately outside $fillable.
        $playlist->forceFill([
            'tracks_count' => (int) ($totals->tracks ?? 0),
            'total_duration' => (int) ($totals->duration ?? 0),
        ])->save();
    }

    /** @return Builder<Playlist> */
    private function baseQuery(): Builder
    {
        return Playlist::query()->with('owner');
    }

    /** @return Builder<PlaylistTrack> */
    private function trackQuery(Playlist $playlist, Song $song): Builder
    {
        return PlaylistTrack::query()
            ->where('playlist_id', $playlist->getKey())
            ->where('song_id', $song->getKey());
    }
}
