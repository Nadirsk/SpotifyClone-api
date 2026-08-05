<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\Repositories\SongRepository;
use App\DTO\CatalogQuery;
use App\Models\Song;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Read side of the song catalog (05_API_SPECIFICATION §6).
 */
final class SongService
{
    /**
     * Everything SongResource may serialise. `Model::preventLazyLoading()` is on
     * outside production, so a relation the resource touches but nobody loaded
     * is an exception, not a lazy query.
     *
     * @var list<string>
     */
    private const RELATIONS = ['artist', 'album', 'genre', 'language'];

    public function __construct(
        private readonly SongRepository $songs,
        private readonly CacheService $cache,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Song>
     */
    public function paginate(CatalogQuery $query): LengthAwarePaginator
    {
        return $this->cache->remember(
            'song',
            $query->cacheKey('songs'),
            function () use ($query): LengthAwarePaginator {
                $paginator = $this->songs->paginate($query);
                $this->eagerLoad($paginator->items());

                return $paginator;
            },
        );
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Song
    {
        return $this->cache->remember('song', "show:{$id}", function () use ($id): Song {
            $song = $this->songs->findOrFail($id);
            $song->loadMissing(self::RELATIONS);

            return $song;
        });
    }

    /**
     * @return Collection<int, Song>
     *
     * @throws ModelNotFoundException
     */
    public function related(string $id, ?int $limit = null): Collection
    {
        $limit = $this->resolveLimit($limit);

        return $this->cache->remember(
            'song',
            "related:{$id}:{$limit}",
            function () use ($id, $limit): Collection {
                $related = $this->songs->related($this->songs->findOrFail($id), $limit);
                $this->eagerLoad($related->all());

                return $related;
            },
        );
    }

    /**
     * Preview payload for GET /songs/{id}/preview.
     *
     * This hands back the provider's own preview URL and nothing more. The API
     * deliberately does not proxy or re-stream provider audio: doing so would
     * breach every provider's terms and put us in the delivery path for content
     * we have no licence to serve.
     *
     * @return array{id: string, title: string, duration: int|null, preview_url: string|null, external_url: string|null}
     *
     * @throws ModelNotFoundException
     */
    public function preview(string $id): array
    {
        $song = $this->find($id);

        return [
            'id' => (string) $song->getKey(),
            'title' => (string) $song->title,
            'duration' => $song->duration,
            'preview_url' => $song->preview_url,
            'external_url' => $song->external_url,
        ];
    }

    /**
     * Models are held by reference, so loading onto a throwaway Eloquent
     * collection also populates the instances the caller already holds. This
     * keeps the service working with whatever collection type the repository
     * hands back, paginator items included.
     *
     * @param  list<Song>  $songs
     */
    private function eagerLoad(array $songs): void
    {
        EloquentCollection::make($songs)->loadMissing(self::RELATIONS);
    }

    private function resolveLimit(?int $limit): int
    {
        $default = (int) config('music.pagination.default_limit');
        $max = (int) config('music.pagination.max_limit');

        return min($max, max(1, $limit ?? $default));
    }
}
