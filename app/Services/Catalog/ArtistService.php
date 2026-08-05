<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\Repositories\AlbumRepository;
use App\Contracts\Repositories\ArtistRepository;
use App\Contracts\Repositories\SongRepository;
use App\DTO\CatalogQuery;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Read side of the artist catalog (05_API_SPECIFICATION §7).
 */
final class ArtistService
{
    /**
     * ArtistResource renders these through `whenCounted`, so they are loaded on
     * the endpoints where the artist is the primary entity and omitted where an
     * artist is only a nested label.
     *
     * @var list<string>
     */
    private const COUNTS = ['albums', 'songs'];

    /** @var list<string> */
    private const ALBUM_RELATIONS = ['artist', 'language'];

    /** @var list<string> */
    private const SONG_RELATIONS = ['artist', 'album', 'genre', 'language'];

    public function __construct(
        private readonly ArtistRepository $artists,
        private readonly AlbumRepository $albums,
        private readonly SongRepository $songs,
        private readonly CacheService $cache,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Artist>
     */
    public function paginate(CatalogQuery $query): LengthAwarePaginator
    {
        return $this->cache->remember(
            'artist',
            $query->cacheKey('artists'),
            function () use ($query): LengthAwarePaginator {
                $paginator = $this->artists->paginate($query);
                EloquentCollection::make($paginator->items())->loadCount(self::COUNTS);

                return $paginator;
            },
        );
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Artist
    {
        return $this->cache->remember('artist', "show:{$id}", function () use ($id): Artist {
            $artist = $this->artists->findOrFail($id);
            $artist->loadCount(self::COUNTS);

            return $artist;
        });
    }

    /**
     * @return LengthAwarePaginator<int, Album>
     *
     * @throws ModelNotFoundException
     */
    public function albums(string $id, CatalogQuery $query): LengthAwarePaginator
    {
        // Resolve first so an unknown artist is a 404 rather than an empty page.
        $artist = $this->artists->findOrFail($id);

        return $this->cache->remember(
            'artist',
            $query->cacheKey("artist:{$artist->getKey()}:albums"),
            function () use ($artist, $query): LengthAwarePaginator {
                $paginator = $this->albums->forArtist((string) $artist->getKey(), $query);
                EloquentCollection::make($paginator->items())->loadMissing(self::ALBUM_RELATIONS);

                return $paginator;
            },
        );
    }

    /**
     * @return LengthAwarePaginator<int, Song>
     *
     * @throws ModelNotFoundException
     */
    public function songs(string $id, CatalogQuery $query): LengthAwarePaginator
    {
        $artist = $this->artists->findOrFail($id);

        return $this->cache->remember(
            'artist',
            $query->cacheKey("artist:{$artist->getKey()}:songs"),
            function () use ($artist, $query): LengthAwarePaginator {
                $paginator = $this->songs->forArtist((string) $artist->getKey(), $query);
                EloquentCollection::make($paginator->items())->loadMissing(self::SONG_RELATIONS);

                return $paginator;
            },
        );
    }

    /**
     * @return Collection<int, Artist>
     *
     * @throws ModelNotFoundException
     */
    public function related(string $id, ?int $limit = null): Collection
    {
        $limit = $this->resolveLimit($limit);

        return $this->cache->remember(
            'artist',
            "related:{$id}:{$limit}",
            function () use ($id, $limit): Collection {
                $related = $this->artists->related($this->artists->findOrFail($id), $limit);

                /*
                 | Models are held by reference, so aggregating onto a throwaway
                 | Eloquent collection populates the instances being returned —
                 | this works whatever concrete collection the repository used.
                 */
                EloquentCollection::make($related->all())->loadCount(self::COUNTS);

                return $related;
            },
        );
    }

    private function resolveLimit(?int $limit): int
    {
        $default = (int) config('music.pagination.default_limit');
        $max = (int) config('music.pagination.max_limit');

        return min($max, max(1, $limit ?? $default));
    }
}
