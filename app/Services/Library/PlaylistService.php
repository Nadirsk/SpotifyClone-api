<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\PlaylistRepository;
use App\Contracts\Repositories\SongRepository;
use App\Exceptions\DomainException;
use App\Models\Playlist;
use App\Models\User;
use App\Policies\PlaylistPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Playlist CRUD and track management (05_API_SPECIFICATION §9).
 *
 * Ownership checks live in {@see PlaylistPolicy} and are applied
 * by the controller; this service assumes the caller is already authorized and
 * only enforces the business caps.
 */
final class PlaylistService
{
    /**
     * Relations SongResource nests. With `Model::preventLazyLoading()` on
     * outside production, a missing one is a 500 rather than an extra query, so
     * they are loaded up front for the detail view.
     *
     * @var list<string>
     */
    private const TRACK_RELATIONS = [
        'songs.artist',
        'songs.album',
        'songs.genre',
        'songs.language',
    ];

    public function __construct(
        private readonly PlaylistRepository $playlists,
        private readonly SongRepository $songs,
    ) {}

    /**
     * Playlists the viewer may see in a listing: their own plus public ones.
     * Unlisted playlists are excluded by the repository — they are link-only.
     *
     * @return LengthAwarePaginator<int, Playlist>
     */
    public function list(?User $viewer, int $page, int $limit): LengthAwarePaginator
    {
        return $this->playlists->paginateVisibleTo($viewer, $page, $limit);
    }

    /**
     * The bare model, for the controller to authorize against before a mutation.
     *
     * @throws ModelNotFoundException
     */
    public function find(string $id): Playlist
    {
        return $this->playlists->findOrFail($id);
    }

    /**
     * The detail view: same model, with everything the resource serialises.
     *
     * @throws ModelNotFoundException
     */
    public function show(string $id): Playlist
    {
        return $this->find($id)->load(['owner', ...self::TRACK_RELATIONS]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws DomainException When the owner is at the per-user cap.
     */
    public function create(User $owner, array $attributes): Playlist
    {
        $max = (int) config('music.playlists.max_per_user');

        if ($this->countFor($owner) >= $max) {
            throw DomainException::playlistLimitReached($max);
        }

        $playlist = $this->playlists->create($owner, [
            ...$attributes,
            // `slug` is NOT NULL; derived from the submitted title so the value
            // is deterministic. It is not unique — the UUID identifies the row.
            'slug' => Str::slug((string) $attributes['title']),
        ]);

        // The owner is already in memory; setting the relation saves a query
        // when the resource serialises it.
        return $playlist->setRelation('owner', $owner);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Playlist $playlist, array $attributes): Playlist
    {
        // The slug is deliberately left untouched on rename: it is part of URLs
        // that may already have been shared.
        return $this->playlists->update($playlist, $attributes);
    }

    public function delete(Playlist $playlist): void
    {
        $this->playlists->delete($playlist);
    }

    /**
     * @throws DomainException When the playlist is full or already holds the song.
     * @throws ModelNotFoundException
     */
    public function addSong(Playlist $playlist, string $songId): Playlist
    {
        $max = (int) config('music.playlists.max_tracks');

        // tracks_count is denormalised on the row, so the cap costs no query.
        if ($playlist->tracks_count >= $max) {
            throw DomainException::playlistFull($max);
        }

        $song = $this->songs->findOrFail($songId);

        if (! $this->playlists->addSong($playlist, $song)) {
            throw DomainException::songAlreadyInPlaylist();
        }

        $this->playlists->refreshCounters($playlist);

        // refreshCounters writes straight to the table, so the in-memory model
        // still carries the pre-add counters.
        return $playlist->refresh();
    }

    /**
     * @throws DomainException When the song is not in the playlist.
     * @throws ModelNotFoundException
     */
    public function removeSong(Playlist $playlist, string $songId): void
    {
        $song = $this->songs->findOrFail($songId);

        if (! $this->playlists->removeSong($playlist, $song)) {
            throw DomainException::songNotInPlaylist();
        }

        $this->playlists->refreshCounters($playlist);
    }

    /**
     * The repository contract exposes no count, and a service must not query a
     * model directly, so the owner listing's total is used instead. Asking for
     * a single row keeps the payload of the accompanying select negligible.
     */
    private function countFor(User $owner): int
    {
        return $this->playlists->paginateForOwner($owner, 1, 1)->total();
    }
}
