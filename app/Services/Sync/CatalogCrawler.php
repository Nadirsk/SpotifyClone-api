<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Contracts\Providers\ProviderAdapter;
use App\Contracts\Providers\SupportsCatalogCrawl;
use App\Contracts\Providers\SupportsPlaylists;
use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderPage;
use App\DTO\Providers\ProviderSongData;
use App\Enums\CrawlType;
use App\Exceptions\ProviderUnavailableException;
use App\Models\CrawlTarget;
use App\Models\Provider;
use App\Services\Providers\JioSaavn\JioSaavnAdapter;
use Psr\Log\LoggerInterface;

/**
 * Executes one crawl target: fetches what it names, persists it, and queues
 * whatever it discovered.
 *
 * This is the discovery half of the sync engine. {@see SyncService} keeps
 * records we already have fresh; this finds the ones we do not have. Neither
 * knows about the other's scheduling — the crawler only ever asks the frontier
 * for work and hands results to the sync services.
 *
 * The shape of every handler is the same three steps, and the third is the one
 * that matters:
 *
 * 1. fetch a page (or a detail) from the provider,
 * 2. persist it through SyncService / PlaylistSyncService,
 * 3. **enqueue what it mentioned.**
 *
 * Step 3 is what makes the crawl exhaustive rather than a fixed list of work.
 * A song names an artist; an artist names albums and similar artists; an album
 * names its tracks' artists. Following those references closes over the
 * reachable catalog, and the frontier's unique key is what stops the closure
 * looping.
 *
 * Paged targets do not run to completion in one visit. They walk at most
 * `providers.crawl.pages_per_visit` pages and then reschedule themselves at
 * the next page, so a 458-page discography yields the worker back to the rest
 * of the frontier instead of holding it for an hour.
 */
final class CatalogCrawler
{
    public function __construct(
        private readonly CrawlFrontier $frontier,
        private readonly SyncService $sync,
        private readonly PlaylistSyncService $playlists,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Run one target to its next stopping point.
     *
     * Never throws for an ordinary provider failure — the frontier records it
     * and the target is retried or parked. A {@see ProviderUnavailableException}
     * is the one case that propagates, because it means "stop crawling
     * entirely", not "this target is bad": burning attempts against a dead
     * provider would park the whole frontier as `failed`.
     *
     * @throws ProviderUnavailableException
     */
    public function crawl(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        try {
            return $this->dispatch($record, $adapter, $target);
        } catch (ProviderUnavailableException $exception) {
            // Hand the target back untouched; the provider, not the target, is
            // the problem. The caller stops the run.
            $this->frontier->release($target);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->frontier->fail($target, $exception->getMessage());

            $this->logger->warning('Crawl target failed', [
                'type' => $target->type->value,
                'identifier' => $target->identifier,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /** @throws ProviderUnavailableException */
    private function dispatch(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        return match ($target->type) {
            CrawlType::SearchTerm => $this->fanOutSearchTerm($target),
            CrawlType::SearchSongs,
            CrawlType::SearchAlbums,
            CrawlType::SearchArtists,
            CrawlType::SearchPlaylists => $this->walkSearch($record, $adapter, $target),
            CrawlType::Artist => $this->crawlArtist($record, $adapter, $target),
            CrawlType::ArtistSongs => $this->walkArtistSongs($record, $adapter, $target),
            CrawlType::ArtistAlbums => $this->walkArtistAlbums($record, $adapter, $target),
            CrawlType::ArtistLatest => $this->probeArtistLatest($record, $adapter, $target),
            CrawlType::Album => $this->crawlAlbum($record, $adapter, $target),
            CrawlType::Playlist => $this->walkPlaylist($record, $adapter, $target),
            CrawlType::SongSuggestions => $this->crawlSuggestions($record, $adapter, $target),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    /**
     * Turn one seed term into the four per-type search walks.
     *
     * Makes no provider request of its own. Fanning out rather than looping
     * inline means a term's songs keep being walked while its playlists are
     * still going, and that no single broad term holds a lease for the length
     * of four exhaustive listings.
     */
    private function fanOutSearchTerm(CrawlTarget $target): int
    {
        foreach ([CrawlType::SearchSongs, CrawlType::SearchAlbums, CrawlType::SearchArtists, CrawlType::SearchPlaylists] as $type) {
            $this->frontier->enqueue($target->provider, $type, $target->identifier);
        }

        $this->frontier->complete($target, 0);

        return 0;
    }

    /**
     * Walk one search listing to the provider's own total.
     *
     * This is the "search returns everything, and everything is stored"
     * requirement. The adapter pages internally, so a visit asks for the whole
     * remaining result set at once and the target completes in one go for all
     * but the broadest terms — `pages_per_visit` bounds it in units of the
     * adapter's page size.
     *
     * @throws ProviderUnavailableException
     */
    private function walkSearch(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        $searchType = $target->type->searchType();

        if ($searchType === null) {
            $this->frontier->complete($target, 0);

            return 0;
        }

        $term = $target->identifier;
        $cap = (int) config('providers.crawl.search_result_cap', 5000);

        /*
         | Ask the provider how much it has before asking for any of it. One
         | cheap page-zero request turns "fetch up to 5,000 and see" into
         | "fetch exactly the 37 that exist", which for a catalog of terms is
         | the difference between a crawl that is mostly empty pages and one
         | that is mostly data.
         */
        $total = $adapter instanceof JioSaavnAdapter
            ? $adapter->searchTotal($searchType, $term)
            : null;

        $want = $total === null ? $cap : min($cap, max(1, $total));

        $synced = match ($target->type) {
            CrawlType::SearchSongs => $this->persistSongs($record, $target, $adapter->searchSongs($term, $want)),
            CrawlType::SearchAlbums => $this->persistAlbums($record, $target, $adapter->searchAlbums($term, $want)),
            CrawlType::SearchArtists => $this->persistArtists($record, $target, $adapter->searchArtists($term, $want)),
            CrawlType::SearchPlaylists => $this->queuePlaylists($adapter, $target, $want),
            default => 0,
        };

        $target->forceFill(['total_expected' => $total])->save();
        $this->frontier->complete($target, $synced);

        return $synced;
    }

    /**
     * Search hits are queued rather than synced: a search result carries only
     * a playlist's name and cover, and its tracklist — the reason to store it
     * at all — needs a separate detail call per playlist. Queueing lets those
     * happen as their own targets instead of serialising them behind one lease.
     *
     * @throws ProviderUnavailableException
     */
    private function queuePlaylists(ProviderAdapter $adapter, CrawlTarget $target, int $limit): int
    {
        if (! $adapter instanceof SupportsPlaylists) {
            return 0;
        }

        $queued = 0;

        foreach ($adapter->searchPlaylists($target->identifier, $limit) as $playlist) {
            if ($this->frontier->enqueue($target->provider, CrawlType::Playlist, $playlist->externalId)) {
                $queued++;
            }
        }

        return $queued;
    }

    /*
    |--------------------------------------------------------------------------
    | Artists
    |--------------------------------------------------------------------------
    */

    /**
     * Fetch the rich artist record and turn it into further targets.
     *
     * One request that pays for itself several times over: `/artists/{id}`
     * carries the bio and follower count that a search-sourced artist stub
     * lacks, *and* names up to 40 further entities inline (`topSongs`,
     * `topAlbums`, `singles`, `similarArtists`). Harvesting those costs nothing
     * extra and is most of what keeps the frontier fed.
     *
     * @throws ProviderUnavailableException
     */
    private function crawlArtist(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        $synced = 0;
        $artist = $adapter->getArtist($target->identifier);

        if ($artist !== null && $this->sync->syncArtist($record, $artist) !== null) {
            $synced = 1;
        }

        // Queue the discography walks regardless of whether the detail
        // resolved — the listings are addressed by the same ID and frequently
        // answer even when the detail record does not.
        $this->frontier->enqueue($target->provider, CrawlType::ArtistSongs, $target->identifier);
        $this->frontier->enqueue($target->provider, CrawlType::ArtistAlbums, $target->identifier);
        $this->frontier->enqueue($target->provider, CrawlType::ArtistLatest, $target->identifier);

        if ($adapter instanceof JioSaavnAdapter) {
            $related = $adapter->artistRelatedIds($target->identifier);

            $this->frontier->enqueueMany($target->provider, CrawlType::Album, $related['albums']);

            if ($this->expandsArtists()) {
                $this->frontier->enqueueMany($target->provider, CrawlType::Artist, $related['artists']);
            }
        }

        $this->frontier->complete($target, $synced);

        return $synced;
    }

    /** @throws ProviderUnavailableException */
    private function walkArtistSongs(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        if (! $adapter instanceof SupportsCatalogCrawl) {
            $this->frontier->complete($target, 0);

            return 0;
        }

        return $this->walkPages(
            $target,
            fetch: fn (int $page): ProviderPage => $adapter->artistSongs($target->identifier, $page),
            persist: fn (array $items): int => $this->persistSongs($record, $target, $items),
        );
    }

    /** @throws ProviderUnavailableException */
    private function walkArtistAlbums(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        if (! $adapter instanceof SupportsCatalogCrawl) {
            $this->frontier->complete($target, 0);

            return 0;
        }

        return $this->walkPages(
            $target,
            fetch: fn (int $page): ProviderPage => $adapter->artistAlbums($target->identifier, $page),
            persist: fn (array $items): int => $this->persistAlbums($record, $target, $items),
        );
    }

    /**
     * The cheap new-release probe: page zero of both listings, newest first.
     *
     * Two requests per artist, against the hundreds a full discography re-walk
     * costs. A new release lands at the top of a date-sorted listing the moment
     * it drops, so this is what actually delivers "new songs appear in my DB
     * automatically" — the exhaustive re-walk is a much slower backstop for
     * anything that slips past.
     *
     * Always completes in one visit and is re-opened frequently by
     * {@see \App\Jobs\DiscoverNewReleasesJob}.
     *
     * @throws ProviderUnavailableException
     */
    private function probeArtistLatest(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        if (! $adapter instanceof SupportsCatalogCrawl) {
            $this->frontier->complete($target, 0);

            return 0;
        }

        $songs = $adapter->artistSongs($target->identifier, 0, newestFirst: true);
        $albums = $adapter->artistAlbums($target->identifier, 0, newestFirst: true);

        $synced = $this->persistSongs($record, $target, $songs->items)
            + $this->persistAlbums($record, $target, $albums->items);

        $this->frontier->complete($target, $synced);

        return $synced;
    }

    /*
    |--------------------------------------------------------------------------
    | Albums and playlists
    |--------------------------------------------------------------------------
    */

    /**
     * An album and its full tracklist.
     *
     * Never paged: `/albums?id=` embeds every song's complete record in the
     * same response as the album metadata, so one request is the whole thing.
     *
     * @throws ProviderUnavailableException
     */
    private function crawlAlbum(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        $synced = 0;
        $album = $adapter->getAlbum($target->identifier);

        if ($album !== null) {
            $synced += $this->persistAlbums($record, $target, [$album]);
        }

        if ($adapter instanceof JioSaavnAdapter) {
            $synced += $this->persistSongs($record, $target, $adapter->albumTracks($target->identifier));
        }

        $this->frontier->complete($target, $synced);

        return $synced;
    }

    /**
     * A playlist and its tracklist.
     *
     * **One request, always.** JioSaavn will not serve more than 50 tracks of
     * a playlist through any combination of paging — verified against its own
     * `playlist.getDetails` endpoint, which answers `list_count: 50` even when
     * asked for 100, and returns nothing at all for the page after the 50th
     * track. Walking `limit=10` across eight pages yields the same 50 unique
     * IDs and then empties, so smaller pages do not get past it either.
     *
     * A playlist's real length is therefore only knowable from a *search* hit,
     * whose `songCount` is genuine (100 for "Hindi 1990s", of which 50 are
     * retrievable). Detail responses report the page size instead, which is
     * why {@see \App\Services\Providers\JioSaavn\JioSaavnAdapter::mapPlaylist()}
     * discards that field.
     *
     * The loop this used to be cost one guaranteed-empty request per playlist
     * to rediscover the ceiling each time. If a future wrapper or a paid API
     * tier lifts it, this is where paging goes back in — reinstate the walk
     * against {@see CrawlTarget::$cursor_page} and stop on a short page.
     *
     * @throws ProviderUnavailableException
     */
    private function walkPlaylist(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        if (! $adapter instanceof SupportsPlaylists) {
            $this->frontier->complete($target, 0);

            return 0;
        }

        $playlist = $adapter->getPlaylist($target->identifier, page: 0, limit: 50);

        if ($playlist === null) {
            // The provider answered and has no such playlist. Completing
            // rather than failing keeps a delisted playlist from cycling
            // through retries until it is parked.
            $this->frontier->complete($target, 0);

            return 0;
        }

        $synced = $this->playlists->sync($record, $playlist, replaceTracks: true) !== null ? 1 : 0;

        $this->queueSongReferences($target, $playlist->songs);

        $this->frontier->complete($target, $synced);

        return $synced;
    }

    /**
     * Follow the provider's "play next" station out from a song.
     *
     * @throws ProviderUnavailableException
     */
    private function crawlSuggestions(Provider $record, ProviderAdapter $adapter, CrawlTarget $target): int
    {
        if (! $adapter instanceof SupportsCatalogCrawl) {
            $this->frontier->complete($target, 0);

            return 0;
        }

        $limit = (int) config('providers.crawl.suggestion_limit', 20);
        $synced = $this->persistSongs($record, $target, $adapter->songSuggestions($target->identifier, $limit));

        $this->frontier->complete($target, $synced);

        return $synced;
    }

    /*
    |--------------------------------------------------------------------------
    | Paging
    |--------------------------------------------------------------------------
    */

    /**
     * Walk a paged provider listing, stopping at `pages_per_visit` and
     * rescheduling if there is more.
     *
     * The stop condition is {@see ProviderPage::hasMore()} against the running
     * count, never a short page — JioSaavn returns short pages routinely while
     * more records genuinely remain.
     *
     * @param  callable(int): ProviderPage<ProviderSongData|ProviderAlbumData>  $fetch
     * @param  callable(list<ProviderSongData|ProviderAlbumData>): int  $persist
     *
     * @throws ProviderUnavailableException
     */
    private function walkPages(CrawlTarget $target, callable $fetch, callable $persist): int
    {
        $maxPages = $this->pagesPerVisit();
        $page = $target->cursor_page;
        $synced = 0;
        $total = $target->total_expected;

        /*
         | Seen-so-far has to include what previous visits already walked, or a
         | resumed target would compare this visit's handful of items against
         | the listing's full total and never believe it was finished.
         */
        $seen = $page * $this->assumedPageSize($target);

        for ($walked = 0; $walked < $maxPages; $walked++) {
            $result = $fetch($page);

            $total ??= $result->total;
            $seen += count($result->items);
            $synced += $persist($result->items);
            $page++;

            if (! $result->hasMore($seen)) {
                $this->frontier->complete($target, $synced);

                return $synced;
            }
        }

        $this->frontier->reschedule($target, $page, $synced, $total);

        return $synced;
    }

    /**
     * Items per page for the listing this target walks, used only to
     * reconstruct "how many have I seen" when resuming mid-walk.
     *
     * Both artist listings are a hard 10 per page — no parameter changes it —
     * and playlists are 50. An over-estimate here would make a resumed target
     * think it had finished early, so these track the measured values rather
     * than the requested ones.
     */
    private function assumedPageSize(CrawlTarget $target): int
    {
        return match ($target->type) {
            CrawlType::ArtistSongs, CrawlType::ArtistAlbums => 10,
            CrawlType::Playlist => 50,
            default => 40,
        };
    }

    private function pagesPerVisit(): int
    {
        return max(1, (int) config('providers.crawl.pages_per_visit', 40));
    }

    /*
    |--------------------------------------------------------------------------
    | Persistence + expansion
    |--------------------------------------------------------------------------
    */

    /**
     * Persist songs and queue the entities they mention.
     *
     * @param  list<ProviderSongData>  $songs
     */
    private function persistSongs(Provider $record, CrawlTarget $target, array $songs): int
    {
        $synced = $this->sync->syncSongs($record, $songs);

        $this->queueSongReferences($target, $songs);

        return $synced;
    }

    /**
     * @param  list<ProviderAlbumData>  $albums
     */
    private function persistAlbums(Provider $record, CrawlTarget $target, array $albums): int
    {
        $synced = $this->sync->syncAlbums($record, $albums);

        /*
         | Every album gets its own target so its tracklist is pulled. This is
         | the single biggest source of songs in the crawl: an album detail
         | embeds all of its tracks, so one request per album beats discovering
         | those same tracks one search hit at a time.
         */
        foreach ($albums as $album) {
            $this->frontier->enqueue($target->provider, CrawlType::Album, $album->externalId);

            if ($this->expandsArtists()) {
                $this->frontier->enqueueMany($target->provider, CrawlType::Artist, $album->artistIds);
            }
        }

        return $synced;
    }

    /**
     * @param  list<ProviderArtistData>  $artists
     */
    private function persistArtists(Provider $record, CrawlTarget $target, array $artists): int
    {
        $synced = $this->sync->syncArtists($record, $artists);

        foreach ($artists as $artist) {
            $this->frontier->enqueue($target->provider, CrawlType::Artist, $artist->externalId);
        }

        return $synced;
    }

    /**
     * Queue everything a batch of songs points at.
     *
     * This is the single most productive expansion step in the crawl, because
     * songs are what every other target type produces. A song names its album
     * and every artist credited on it — as provider IDs, not just display
     * names, which is what makes them addressable as frontier targets at all.
     * Following those turns "the tracks on this album" into "every artist on
     * this album, and every other album each of them appears on".
     *
     * Artists go in at the album's own priority rather than the artist
     * default. A featured artist discovered from a track is exactly the kind
     * of long-tail entity the crawl exists to reach, and leaving them at the
     * back of the queue behind every discography walk would mean the closure
     * only ever widens once the deep walks are done.
     *
     * @param  list<ProviderSongData>  $songs
     */
    private function queueSongReferences(CrawlTarget $target, array $songs): void
    {
        $expandArtists = $this->expandsArtists();
        $expandSuggestions = (bool) config('providers.crawl.expand_suggestions', false);

        foreach ($songs as $song) {
            if ($song->albumId !== null) {
                $this->frontier->enqueue($target->provider, CrawlType::Album, $song->albumId);
            }

            if ($expandArtists) {
                $this->frontier->enqueueMany(
                    $target->provider,
                    CrawlType::Artist,
                    $song->artistIds,
                    CrawlType::Album->defaultPriority(),
                );
            }

            if ($expandSuggestions) {
                $this->frontier->enqueue($target->provider, CrawlType::SongSuggestions, $song->externalId);
            }
        }
    }

    private function expandsArtists(): bool
    {
        return (bool) config('providers.crawl.expand_artists', true);
    }
}
