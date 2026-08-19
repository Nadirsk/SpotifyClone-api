<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Contracts\Repositories\AlbumRepository;
use App\Contracts\Repositories\ArtistRepository;
use App\Contracts\Repositories\HistoryRepository;
use App\Contracts\Repositories\SongRepository;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use App\Services\Cache\CacheService;
use Carbon\CarbonImmutable;
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
        private readonly HistoryRepository $history,
        private readonly CacheService $cache,
    ) {}

    /**
     * Today's chart: the songs most played since local midnight, most played
     * first.
     *
     * ## Why this is not `songs()`
     *
     * The home page has always had a "Top songs today" shelf and it has always
     * been filled by `songs()` — `trending_score`, which is a 7-day sum decayed
     * by a 48-hour half-life. Those are different questions. Trending is
     * deliberately smooth, so it barely moves between one day and the next; a
     * daily chart is supposed to. Under the trending list the shelf showed
     * yesterday's ranking all morning and called it today's.
     *
     * ## When today is too thin to be a chart
     *
     * Early in the day, or on a quiet catalog, there may be only a handful of
     * plays. Ranking three of them and presenting it as a chart is worse than
     * not having one, so below `music.trending.min_today` this returns *nothing*
     * and the caller falls back to the weekly list under a weekly heading.
     * Silently topping the day up from the week would reproduce exactly the
     * mislabelling this method exists to fix.
     *
     * Not cached against `CacheService`'s 30-minute trending TTL: a chart of
     * the last few hours that updates twice an hour is not a live chart. The
     * query is one indexed GROUP BY over a single day of rows (see the
     * `(played_at, song_id)` index) plus one keyed fetch.
     *
     * @return Collection<int, Song> Empty when today has too little listening.
     */
    public function songsToday(?int $limit = null): Collection
    {
        $limit = $this->resolveLimit($limit);
        $minimum = max(1, (int) config('music.trending.min_today', 4));

        $ranked = $this->history->topSongIdsSince(CarbonImmutable::today(), $limit);

        if ($ranked->count() < $minimum) {
            return Collection::make();
        }

        /*
         | Ordered in PHP against the ranking rather than in SQL: the rank comes
         | from an aggregate over `history`, so keeping it means either a join
         | back onto a derived table or a FIELD() clause listing every id. Both
         | are more machinery than reindexing at most `limit` rows.
         */
        $songs = Song::query()
            ->whereIn('id', $ranked->all())
            ->with(self::SONG_RELATIONS)
            ->get()
            ->keyBy(static fn (Song $song): string => (string) $song->getKey());

        return $ranked
            ->map(static fn (string $id): ?Song => $songs->get($id))
            // A song deleted between the aggregate and the fetch simply drops out.
            ->filter()
            ->values();
    }

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
