<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\Repositories\SearchHistoryRepository;
use App\Contracts\Search\SearchEngine;
use App\DTO\SearchQuery;
use App\DTO\SearchResults;
use App\Jobs\LazySyncSearchJob;
use App\Models\User;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Search orchestration: cache, delegate to the engine, record analytics.
 *
 * The engine is behind App\Contracts\Search\SearchEngine so that swapping
 * MySQL FULLTEXT for Elasticsearch is a config change (docs/DEFERRED.md §2).
 * Nothing in this class knows which driver is in play.
 */
final class SearchService
{
    public function __construct(
        private readonly SearchEngine $engine,
        private readonly SearchHistoryRepository $history,
        private readonly CacheService $cache,
    ) {}

    /**
     * Global search across every type, for the search bar's grouped dropdown.
     */
    public function searchAll(SearchQuery $query, ?User $user = null): SearchResults
    {
        /** @var SearchResults $results */
        $results = $this->cache->remember(
            'search',
            $query->cacheKey(),
            fn (): SearchResults => $this->engine->searchAll($query),
        );

        if ($query->hasTerm() && $this->shouldSyncFromProvider($query)) {
            /** @var SearchResults $results */
            $results = $this->syncThenRerun($query, fn (): SearchResults => $this->engine->searchAll($query));
        }

        $this->record($query, $user, $results->total());

        return $results;
    }

    /**
     * Paginated search within one type. $query->type must be a key of
     * config('search.types') — SearchRequest guarantees that.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function searchType(SearchQuery $query, ?User $user = null): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Model> $paginator */
        $paginator = $this->cache->remember(
            'search',
            $query->cacheKey(),
            fn (): LengthAwarePaginator => $this->engine->searchType($query),
        );

        if ($query->hasTerm() && $this->shouldSyncFromProvider($query)) {
            /** @var LengthAwarePaginator<int, Model> $paginator */
            $paginator = $this->syncThenRerun($query, fn (): LengthAwarePaginator => $this->engine->searchType($query));
        }

        $this->record($query, $user, $paginator->total());

        return $paginator;
    }

    /**
     * Autocomplete suggestions.
     *
     * Deliberately not written to search history: a keystroke-per-request
     * endpoint would swamp the analytics in prefixes nobody actually searched
     * for. Only submitted searches count (06_SEARCH_ARCHITECTURE §10).
     *
     * @return Collection<int, string>
     */
    public function suggest(string $term, ?int $limit = null): Collection
    {
        $term = trim($term);
        $limit = $limit ?? (int) config('search.limits.autocomplete');

        return $this->cache->remember(
            'search',
            'suggest:'.$limit.':'.hash('xxh128', mb_strtolower($term)),
            fn (): Collection => $this->engine->suggest($term, $limit),
        );
    }

    /**
     * The signed-in user's own recent keywords. Not cached — it changes on
     * every search the user makes, and it is per-user, so a cache entry would
     * be stale more often than it is warm.
     *
     * @return Collection<int, string>
     */
    public function recentSearches(User $user, ?int $limit = null): Collection
    {
        return $this->history->recentForUser($user, $this->resolveListLimit($limit));
    }

    /**
     * Trailing-window popular keywords across all users.
     *
     * @return Collection<int, string>
     */
    public function popularSearches(?int $limit = null): Collection
    {
        $limit = $this->resolveListLimit($limit);

        // Same trailing window trending uses; there is no separate analytics
        // window in config and inventing one would be a second magic number.
        $days = (int) config('music.trending.window_days');

        return $this->cache->remember(
            'search',
            "popular:{$limit}:{$days}",
            fn (): Collection => $this->history->popular($limit, $days),
        );
    }

    /**
     * Zero-result searches are recorded too — they are the single most useful
     * signal in 06_SEARCH_ARCHITECTURE §10, because they say what the catalog
     * is missing. Recording happens outside the cache callback so a cache hit
     * still counts as a search.
     */
    private function record(SearchQuery $query, ?User $user, int $resultsCount): void
    {
        if (! $query->hasTerm()) {
            return;
        }

        $this->history->record($user, $query->term, $resultsCount);
    }

    /**
     * Whether this search should call the provider before answering.
     *
     * Every search with a term qualifies now, not only a local miss —
     * JioSaavn's own listing for one term routinely runs into the hundreds
     * ("Tum Hi Ho": 1,960; "Die With A Smile": 57), so a local hit of one or
     * two rows is exactly as incomplete as zero. Treating "not empty" as
     * "done" is what used to leave a search stuck at a single row forever,
     * since nothing ever asked the provider again once any match existed.
     *
     * Debounced per (type, term) rather than per request: SyncService's own
     * checksum short-circuit means a same-term resync inside the window would
     * write nothing new anyway, so the debounce only removes a redundant
     * network round trip to JioSaavn, never a real update. This is a
     * provider/sync operational setting rather than domain cache, so it
     * bypasses CacheService the same way AbstractProviderAdapter's
     * throttle/circuit-breaker state does — a fake `music.cache.ttl` bucket
     * would exist only to hold this one flag.
     */
    private function shouldSyncFromProvider(SearchQuery $query): bool
    {
        $debounceKey = implode(':', [
            'providers', 'lazy_sync_debounce', $query->type ?? 'all',
            hash('xxh128', mb_strtolower($query->term)),
        ]);

        if (Cache::has($debounceKey)) {
            return false;
        }

        $debounceMinutes = max(1, (int) config('providers.sync.lazy_debounce_minutes', 15));
        Cache::put($debounceKey, true, $debounceMinutes * 60);

        return true;
    }

    /**
     * Calls the provider and re-answers from the now-current catalog.
     *
     * The request waits for the provider fetch and sync to finish before
     * answering, so the response actually reflects what was just synced
     * instead of the stale cached answer from before the sync ran.
     *
     * `LazySyncSearchJob` already contains the exact fetch-dedupe-upsert logic
     * this needs; `dispatchSync()` runs it in this same process, synchronously,
     * without a queue. If it finds and syncs something, its own `handle()`
     * evicts this query's cache entry (see the job) — so re-running the same
     * cached lookup here either replays the untouched answer (nothing changed
     * upstream) or executes fresh against the now-updated catalog. Either
     * way, no second network round trip from the client.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $rerun
     * @return TResult
     */
    private function syncThenRerun(SearchQuery $query, callable $rerun): mixed
    {
        if ($query->type === null) {
            /*
             | A grouped ("all types") sync is expensive: LazySyncSearchJob
             | chains an artist search + one getArtist() detail fetch per hit,
             | an album search + one getAlbum()/albumTracks() fetch per hit,
             | then a song search — 20+ sequential JioSaavn round trips.
             | Running that inline on every grouped search (not just a rare
             | miss, per shouldSyncFromProvider()) hit PHP's 30-second
             | execution limit and fatally crashed the request — observed
             | live in storage/logs/laravel.log ("Maximum execution time of
             | 30 seconds exceeded"), which is why a search could show
             | nothing at all despite JioSaavn answering it correctly.
             |
             | Only songs — what this view leads with — sync inline, so the
             | request still gets a real JioSaavn-backed answer without the
             | risk. Artists and albums (with track enrichment) sync in the
             | background so a slow provider can never take the request down.
             */
            LazySyncSearchJob::dispatchSync($query->term, 'song', cacheKey: $query->cacheKey());
            LazySyncSearchJob::dispatch($query->term, null, cacheKey: $query->cacheKey());
        } else {
            LazySyncSearchJob::dispatchSync($query->term, $query->type, cacheKey: $query->cacheKey());
        }

        return $this->cache->remember('search', $query->cacheKey(), $rerun);
    }

    private function resolveListLimit(?int $limit): int
    {
        $max = (int) config('music.pagination.max_limit');
        $default = (int) config('search.limits.autocomplete');

        return min($max, max(1, $limit ?? $default));
    }
}
