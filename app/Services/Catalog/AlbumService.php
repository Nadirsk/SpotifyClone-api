<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Contracts\Repositories\AlbumRepository;
use App\Contracts\Repositories\SongRepository;
use App\DTO\CatalogQuery;
use App\Models\Album;
use App\Models\Song;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Read side of the album catalog (05_API_SPECIFICATION §8).
 */
final class AlbumService
{
    /** @var list<string> */
    private const RELATIONS = ['artist', 'language'];

    /**
     * Tracks are loaded with their own artist/genre/language but deliberately
     * without `songs.album`: SongResource would then nest the album that is
     * already the root of this payload.
     *
     * @var list<string>
     */
    private const TRACK_RELATIONS = ['songs.artist', 'songs.genre', 'songs.language'];

    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly SongRepository $songs,
        private readonly CacheService $cache,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Album>
     */
    public function paginate(CatalogQuery $query): LengthAwarePaginator
    {
        return $this->cache->remember(
            'album',
            $query->cacheKey('albums'),
            function () use ($query): LengthAwarePaginator {
                $paginator = $this->albums->paginate($query);
                EloquentCollection::make($paginator->items())->loadMissing(self::RELATIONS);

                return $paginator;
            },
        );
    }

    /**
     * The album detail payload carries its track list, which is what the album
     * page renders in one request. GET /albums/{id}/tracks still exists for
     * clients that only want the list.
     *
     * @throws ModelNotFoundException
     */
    public function find(string $id): Album
    {
        return $this->cache->remember('album', "show:{$id}", function () use ($id): Album {
            $album = $this->albums->findOrFail($id);
            $album->loadMissing([...self::RELATIONS, ...self::TRACK_RELATIONS]);

            return $album;
        });
    }

    /**
     * @return Collection<int, Song>
     *
     * @throws ModelNotFoundException
     */
    public function tracks(string $id): Collection
    {
        $album = $this->albums->findOrFail($id);

        return $this->cache->remember(
            'album',
            "tracks:{$album->getKey()}",
            function () use ($album): Collection {
                $tracks = $this->songs->forAlbum((string) $album->getKey());

                /*
                 | Models are held by reference, so loading onto a throwaway
                 | Eloquent collection populates the instances being returned —
                 | this works whatever concrete collection the repository used.
                 | `album` is left off: the caller already knows which album.
                 */
                EloquentCollection::make($tracks->all())
                    ->loadMissing(['artist', 'genre', 'language']);

                return $tracks;
            },
        );
    }
}
