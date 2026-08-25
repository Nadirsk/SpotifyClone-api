<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\PlaylistRepository;
use App\Models\Playlist;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Moderation, not authorship: this only ever lists, inspects and removes
 * playlists people already made. {@see PlaylistService} is where a
 * playlist is actually created/edited — by its owner, never on their
 * behalf from here.
 */
final class AdminPlaylistService
{
    /**
     * Loaded only for the single-playlist view — see SongRepository::RELATIONS
     * for why a tracklist eager-loads these but a listing never does.
     *
     * @var list<string>
     */
    private const TRACK_RELATIONS = ['songs.artist', 'songs.album', 'songs.genre', 'songs.language'];

    public function __construct(
        private readonly PlaylistRepository $playlists,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Playlist>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        return $this->playlists->adminPaginate($page, $limit, $search);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Playlist
    {
        $playlist = $this->playlists->findOrFail($id);
        $playlist->loadMissing(self::TRACK_RELATIONS);

        return $playlist;
    }

    public function delete(Playlist $playlist): void
    {
        $this->playlists->delete($playlist);
    }
}
