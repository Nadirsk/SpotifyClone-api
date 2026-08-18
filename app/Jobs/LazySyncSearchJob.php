<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Providers\ProviderAdapter;
use App\Contracts\Providers\SupportsCatalogCrawl;
use App\Enums\CrawlType;
use App\Exceptions\ProviderUnavailableException;
use App\Models\Provider;
use App\Services\Cache\CacheService;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\CrawlFrontier;
use App\Services\Sync\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Lazy sync (07_SYNC_ENGINE §5): a user searched for something the local
 * catalog does not have, so go and fetch it in the background.
 *
 * Dispatched after the search response has already gone out — the user gets the
 * local results immediately and the catalog is richer for whoever searches next.
 * Nothing about the HTTP response waits on a provider.
 *
 * Providers are tried in priority order and the run stops at the first one that
 * returns anything. Querying all five for the same term would multiply the API
 * spend for metadata that deduplication would mostly collapse anyway; the
 * incremental sync enriches the record from the other providers later.
 */
final class LazySyncSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 07_SYNC_ENGINE §10. */
    public int $tries = 5;

    /**
     * @param  string  $term  The search term that missed locally.
     * @param  string|null  $type  `song`, `artist` or `album`; null fetches all three.
     * @param  string|null  $cacheKey  The `search` bucket key the miss was cached
     *                                 under (`SearchQuery::cacheKey()`), so a successful sync can evict the
     *                                 stale empty response instead of leaving it to serve for the full TTL.
     */
    public function __construct(
        private readonly string $term,
        private readonly ?string $type = null,
        private readonly ?int $limit = null,
        private readonly ?string $cacheKey = null,
    ) {
        $this->onQueue('sync');
    }

    /**
     * Shorter than the incremental jobs': a lazy sync is chasing a query a user
     * just made, so its value decays quickly and it should not sit in the queue
     * for an hour before giving up.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(ProviderManager $providers, SyncService $sync, CacheService $cache, CrawlFrontier $frontier, LoggerInterface $logger): void
    {
        $term = trim($this->term);

        if ($term === '') {
            return;
        }

        /*
         | `available()`, not `enabled()`: this job can sit in the queue for a
         | while, and a provider that was answering when the search happened may
         | be parked behind a rate limit by the time its turn comes. Every call
         | it made would be suppressed anyway — checking once here is cheaper,
         | and keeps the outage out of the logs one line at a time.
         |
         | Dropped rather than released back to the queue: a lazy sync chases a
         | query someone just made (see the class docblock), and a rate-limit
         | park routinely outlives this job's four retries. The term is not lost
         | — the next person to search it asks again.
         */
        $adapters = $providers->available();

        if ($adapters === []) {
            $logger->debug('LazySyncSearchJob: no provider available, nothing to fetch', ['term' => $term]);

            return;
        }

        /*
         | Hand the term to the crawl frontier before syncing a single record.
         |
         | What follows this is deliberately bounded — `lazy_search_limit` is 50
         | because this job runs inline on somebody's search request and cannot
         | be allowed to spend two minutes walking 125 pages while they wait.
         | But 50 is not "all available results", and JioSaavn routinely reports
         | thousands for an ordinary term. The frontier is where the rest goes:
         | one idempotent insert here turns every term anyone searches into a
         | `search_term` target, which fans out into the four per-type walks and
         | is taken to the provider's own total by the background crawler. The
         | searcher gets the fast bounded answer; the catalog gets everything,
         | usually within the same minute.
         |
         | Before the sync rather than after, and outside the try/catch, because
         | a provider that cuts us off mid-sync is exactly when the frontier
         | matters most — the term is then queued to be retried in full later
         | instead of being lost with the failed request.
         */
        if ($this->isWorthSeeding($term)) {
            foreach ($adapters as $adapter) {
                if ($adapter instanceof SupportsCatalogCrawl) {
                    $frontier->enqueue($adapter->key(), CrawlType::SearchTerm, $term);
                }
            }
        }

        $limit = $this->limit ?? (int) config('providers.sync.lazy_search_limit', 10);
        $detailLimit = max(0, (int) config('providers.sync.detail_fetch_limit', 5));

        foreach ($adapters as $adapter) {
            $record = $providers->record($adapter->key());

            if ($record === null) {
                continue;
            }

            try {
                $synced = $this->syncFrom($adapter, $record, $sync, $term, $limit, $detailLimit);
            } catch (ProviderUnavailableException $exception) {
                /*
                 | The containment boundary. This job runs inline on a user's
                 | search (SearchService::syncThenRerun() dispatches it with
                 | dispatchSync), so an exception escaping here would turn a
                 | provider's bad afternoon into a failed search request — the
                 | exact coupling the exception exists to prevent. The catalog
                 | already in the database answers the search instead.
                 |
                 | Whatever was synced before the provider cut us off stays
                 | synced; partial enrichment is strictly better than none, and
                 | the checksum makes the next attempt idempotent anyway.
                 */
                $logger->warning('Lazy sync stopped: provider unavailable', array_merge(
                    ['term' => $term, 'type' => $this->type ?? 'all'],
                    $exception->context(),
                ));

                /*
                 | Evicted even though we cannot know whether anything was
                 | written: the exception carries no count, and a run cut short
                 | after 30 of 50 songs has still committed those 30. Evicting
                 | when nothing was written costs one uncached query on the next
                 | search; not evicting when something was hides real rows we
                 | already hold until the entry expires on its own.
                 */
                if ($this->cacheKey !== null) {
                    $cache->forget('search', $this->cacheKey);
                }

                continue;
            }

            if ($synced > 0) {
                $logger->info('Lazy sync populated the catalog from a search miss', [
                    'provider' => $adapter->key(),
                    'term' => $term,
                    'type' => $this->type ?? 'all',
                    'synced' => $synced,
                ]);

                /*
                 | Without this, the empty response the user's own request cached
                 | (up to `CACHE_TTL_SEARCH`, 15 minutes by default) would keep
                 | being served back to them even though the catalog now has the
                 | answer — the sync would be invisible until the entry expired
                 | on its own.
                 */
                if ($this->cacheKey !== null) {
                    $cache->forget('search', $this->cacheKey);
                }

                // Highest-priority provider that had an answer wins; stop here.
                return;
            }
        }

        $logger->info('Lazy sync found nothing at any provider', ['term' => $term]);
    }

    /**
     * Fetch and sync one provider's answer for this term, and report how many
     * rows it moved.
     *
     * Split out from `handle()` purely so the whole provider conversation sits
     * inside one try block: any of the calls below can find the provider gone
     * mid-run — the 21st `getArtist()` is as likely to meet a 429 as the first —
     * and the caller's single catch is what keeps that from reaching the user.
     *
     * @throws ProviderUnavailableException
     */
    private function syncFrom(
        ProviderAdapter $adapter,
        Provider $record,
        SyncService $sync,
        string $term,
        int $limit,
        int $detailLimit,
    ): int {
        $synced = 0;

        if ($this->type === null || $this->type === 'artist') {
            /*
             | Not the thin search result directly: it carries a name and a
             | photo but never a bio or a follower count (see
             | JioSaavnAdapter::mapArtist()'s doc on why — those fields only
             | exist on the single-artist detail response). Upgrading each
             | hit here means an artist a user actually searched for gets
             | the rich record immediately rather than surfacing as a bare
             | name until `catalog:enrich-artists` next backfills it.
             |
             | Only the leading `detail_fetch_limit` hits, though — see that
             | config key. The rest are still synced, just thin, exactly as
             | they would have been before this upgrade existed.
             */
            $detailed = [];

            foreach ($adapter->searchArtists($term, $limit) as $index => $thin) {
                $detailed[] = $index < $detailLimit
                    ? $adapter->getArtist($thin->externalId) ?? $thin
                    : $thin;
            }

            $synced += $sync->syncArtists($record, $detailed);
        }

        if ($this->type === null || $this->type === 'album') {
            /*
             | Not just syncAlbums(): an album entity alone carries no
             | track list (no provider's DTO has one), so a search-
             | discovered album otherwise sits with zero songs until
             | one happens to also surface from an unrelated song
             | search. Where the adapter can fetch a tracklist directly
             | (JioSaavnAdapter::albumTracks() — not part of
             | ProviderAdapter, see its docblock), use it so an album
             | found this way is actually playable immediately.
             */
            $fetchesTracks = method_exists($adapter, 'albumTracks');
            $tracksFetched = 0;

            foreach ($adapter->searchAlbums($term, $limit) as $albumData) {
                $album = $sync->syncAlbum($record, $albumData);

                if ($album === null) {
                    continue;
                }

                $synced++;

                // Counted on albums actually stored, not on results seen, so
                // a page of duplicates cannot silently spend the budget.
                if ($fetchesTracks && $tracksFetched < $detailLimit) {
                    $tracksFetched++;
                    $synced += $sync->syncSongs($record, $adapter->albumTracks($albumData->externalId));
                }
            }
        }

        if ($this->type === null || $this->type === 'song') {
            $synced += $sync->syncSongs($record, $adapter->searchSongs($term, $limit));
        }

        return $synced;
    }

    /**
     * Whether this term is worth making a permanent crawl seed.
     *
     * Every searched term becomes a frontier target, which is what lets the
     * catalog eventually hold all of a query's results when the inline path
     * could only take 50. A type-ahead box, though, submits the prefixes too:
     * one "KGF chapter" search left `K`, `KGF`, `KGF cha` and `KGF chapter`
     * behind as four separate seeds, and crawling `K` walks a thousand
     * essentially random records.
     *
     * That noise does not merely waste work — search targets sit at the FRONT
     * of the priority order, so it gets crawled *ahead of* the real artist and
     * album backlog queued behind it.
     *
     * Only length is checked, and the threshold is deliberately low: this
     * catalog has genuinely short titles, which is the same reason the server
     * runs `innodb_ft_min_token_size=1`, so anything stricter would start
     * refusing real queries. Longer prefixes of the same phrase are left
     * alone — they return real results, and the frontier's unique key already
     * collapses exact repeats.
     */
    private function isWorthSeeding(string $term): bool
    {
        $minimum = max(1, (int) config('providers.crawl.min_seed_term_length', 3));

        return mb_strlen($term) >= $minimum;
    }
}
