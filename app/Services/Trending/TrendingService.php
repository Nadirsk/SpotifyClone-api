<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Contracts\Repositories\AlbumRepository;
use App\Contracts\Repositories\ArtistRepository;
use App\Contracts\Repositories\SongRepository;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Services\Cache\CacheService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Trending lists (05_API_SPECIFICATION §13).
 *
 * Scoring lives in the repositories — this class only decides how much to ask
 * for and how long the answer stays warm.
 */
final class TrendingService
{
    /** @var list<string> */
    private const SONG_RELATIONS = ['artist', 'album', 'genre', 'language'];

    /** @var list<string> */
    private const ALBUM_RELATIONS = ['artist', 'language'];

    /** @var list<string> */
    private const ARTIST_COUNTS = ['albums', 'songs'];

    public function __construct(
        private readonly SongRepository $songs,
        private readonly ArtistRepository $artists,
        private readonly AlbumRepository $albums,
        private readonly CacheService $cache,
    ) {}

    /**
     * @return Collection<int, Song>
     */
    public function songs(?int $limit = null): Collection
    {
        $limit = $this->resolveLimit($limit);

        return $this->cache->remember('trending', "songs:{$limit}", function () use ($limit): Collection {
            $songs = $this->songs->trending($limit);
            EloquentCollection::make($songs->all())->loadMissing(self::SONG_RELATIONS);

            return $songs;
        });
    }

    /**
     * @return Collection<int, Artist>
     */
    public function artists(?int $limit = null): Collection
    {
        $limit = $this->resolveLimit($limit);

        return $this->cache->remember('trending', "artists:{$limit}", function () use ($limit): Collection {
            $artists = $this->artists->trending($limit);
            EloquentCollection::make($artists->all())->loadCount(self::ARTIST_COUNTS);

            return $artists;
        });
    }

    /**
     * @return Collection<int, Album>
     */
    public function albums(?int $limit = null): Collection
    {
        $limit = $this->resolveLimit($limit);

        return $this->cache->remember('trending', "albums:{$limit}", function () use ($limit): Collection {
            $albums = $this->albums->trending($limit);
            EloquentCollection::make($albums->all())->loadMissing(self::ALBUM_RELATIONS);

            return $albums;
        });
    }

    /**
     * Clamped to the pagination ceiling so a caller cannot ask for an unbounded
     * list through the `limit` query parameter.
     */
    private function resolveLimit(?int $limit): int
    {
        $default = (int) config('music.trending.limit');
        $max = (int) config('music.pagination.max_limit');

        return min($max, max(1, $limit ?? $default));
    }
}
