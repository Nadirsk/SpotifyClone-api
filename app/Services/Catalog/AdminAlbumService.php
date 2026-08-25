<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\Repositories\AlbumRepository;
use App\Models\Album;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Write side of the album catalog, for the admin panel — {@see AlbumService}
 * (if one exists) or the album-reading paths on other services stay
 * read-only.
 */
final class AdminAlbumService
{
    private const RELATIONS = ['artist', 'language'];

    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly CacheService $cache,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Album>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        return $this->albums->adminPaginate($page, $limit, $search);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Album
    {
        $album = $this->albums->findOrFail($id);
        $album->loadMissing(self::RELATIONS);

        return $album;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Album
    {
        $album = $this->albums->create([
            ...$data,
            // Deterministic from the title; not unique — the UUID identifies
            // the row (see PlaylistService::create for the same pattern).
            'slug' => Str::slug((string) $data['title']),
        ]);

        $album->loadMissing(self::RELATIONS);

        return $album;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Album $album, array $data): Album
    {
        $album = $this->albums->update($album, $data);
        $album->loadMissing(self::RELATIONS);

        $this->forgetPublicCache($album->getKey());

        return $album;
    }

    public function delete(Album $album): void
    {
        $this->albums->delete($album);

        $this->forgetPublicCache($album->getKey());
    }

    /**
     * Best-effort invalidation of the public-facing `GET /albums/{id}` and
     * its tracks cache. Public *listings* are keyed by a hash of their
     * filters and cannot be targeted individually — they catch up once the
     * `album` bucket's TTL (config('music.cache.ttl.album')) expires.
     */
    private function forgetPublicCache(string|int $id): void
    {
        $this->cache->forgetMany('album', ["show:{$id}", "tracks:{$id}"]);
    }
}
