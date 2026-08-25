<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\Repositories\SongRepository;
use App\DTO\CatalogQuery;
use App\Models\Song;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Write side of the song catalog, for the admin panel — {@see SongService} is
 * deliberately read-only.
 */
final class AdminSongService
{
    private const RELATIONS = ['artist', 'album', 'genre', 'language'];

    public function __construct(
        private readonly SongRepository $songs,
        private readonly CacheService $cache,
    ) {}

    /**
     * Never cached, unlike SongService::paginate() — an admin editing a song
     * needs to see the result immediately, not after the `song` bucket's
     * hour-long TTL.
     *
     * @return LengthAwarePaginator<int, Song>
     */
    public function paginate(CatalogQuery $query, ?string $search): LengthAwarePaginator
    {
        return $this->songs->adminPaginate($query, $search);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Song
    {
        $song = $this->songs->findOrFail($id);
        $song->loadMissing(self::RELATIONS);

        return $song;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Song
    {
        $song = $this->songs->create([
            ...$data,
            // Deterministic from the title; not unique — the UUID identifies
            // the row (see PlaylistService::create for the same pattern).
            'slug' => Str::slug((string) $data['title']),
        ]);

        $song->loadMissing(self::RELATIONS);

        return $song;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Song $song, array $data): Song
    {
        $song = $this->songs->update($song, $data);
        $song->loadMissing(self::RELATIONS);

        $this->forgetPublicCache($song->getKey());

        return $song;
    }

    public function delete(Song $song): void
    {
        $this->songs->delete($song);

        $this->forgetPublicCache($song->getKey());
    }

    /**
     * Best-effort invalidation of the public-facing `GET /songs/{id}` cache.
     * Public *listings* are keyed by a hash of their filters and cannot be
     * targeted individually — they catch up once the `song` bucket's TTL
     * (config('music.cache.ttl.song')) expires.
     */
    private function forgetPublicCache(string|int $id): void
    {
        $this->cache->forget('song', "show:{$id}");
    }
}
