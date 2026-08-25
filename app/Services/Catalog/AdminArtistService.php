<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\Repositories\ArtistRepository;
use App\Models\Artist;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Write side of the artist catalog, for the admin panel — {@see ArtistService}
 * is deliberately read-only.
 */
final class AdminArtistService
{
    public function __construct(
        private readonly ArtistRepository $artists,
        private readonly CacheService $cache,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Artist>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        return $this->artists->adminPaginate($page, $limit, $search);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Artist
    {
        return $this->artists->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Artist
    {
        return $this->artists->create([
            ...$data,
            // Deterministic from the name; not unique — the UUID identifies
            // the row (see PlaylistService::create for the same pattern).
            'slug' => Str::slug((string) $data['name']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Artist $artist, array $data): Artist
    {
        $artist = $this->artists->update($artist, $data);

        $this->forgetPublicCache($artist->getKey());

        return $artist;
    }

    public function delete(Artist $artist): void
    {
        $this->artists->delete($artist);

        $this->forgetPublicCache($artist->getKey());
    }

    /**
     * Best-effort invalidation of the public-facing `GET /artists/{id}`
     * cache. Public *listings* are keyed by a hash of their filters and
     * cannot be targeted individually — they catch up once the `artist`
     * bucket's TTL (config('music.cache.ttl.artist')) expires.
     */
    private function forgetPublicCache(string|int $id): void
    {
        $this->cache->forget('artist', "show:{$id}");
    }
}
