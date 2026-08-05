<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface PlaylistRepository
{
    /**
     * Playlists $viewer is allowed to list: their own plus everyone's public
     * ones. Unlisted playlists are excluded — they are link-only by design.
     *
     * @return LengthAwarePaginator<int, Playlist>
     */
    public function paginateVisibleTo(?User $viewer, int $page, int $limit): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, Playlist> */
    public function paginateForOwner(User $owner, int $page, int $limit): LengthAwarePaginator;

    /** @throws ModelNotFoundException */
    public function findOrFail(string $id): Playlist;

    /** @param  array<string, mixed>  $attributes */
    public function create(User $owner, array $attributes): Playlist;

    /** @param  array<string, mixed>  $attributes */
    public function update(Playlist $playlist, array $attributes): Playlist;

    public function delete(Playlist $playlist): void;

    /**
     * Appends $song at the end. Returns false when the song is already present,
     * so the caller can answer 409 rather than silently doing nothing.
     */
    public function addSong(Playlist $playlist, Song $song): bool;

    /** Returns false when the song was not in the playlist. */
    public function removeSong(Playlist $playlist, Song $song): bool;

    /**
     * Recomputes tracks_count and total_duration from playlist_tracks.
     */
    public function refreshCounters(Playlist $playlist): void;
}
