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
use App\Services\Providers\ProviderManager;
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
        private readonly ProviderManager $providers,
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
     * Whether a search answered right now can still reach the provider, or is
     * limited to what the catalog already holds.
     *
     * Exposed for the response envelope rather than kept private, because a
     * degraded answer and a complete one are indistinguishable from the outside
     * and a client that cannot tell them apart will draw the wrong conclusion
     * from both: "no results" reads as "this music does not exist" when it
     * really means "we could not go and look". The listener's player is
     * unaffected either way — audio comes from the local `preview_url`, never
     * from the provider.
     */
    public function liveSyncAvailable(): bool
    {
        return $this->providers->available() !== [];
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
    /**
     * Types that are this platform's own data, not the provider's.
     *
     * A miss on one of these must never trigger the lazy provider sync: no
     * metadata provider has profiles or this app's genre rows, so the fetch
     * would be a guaranteed-empty outbound round trip that the caller waits on
     * (`syncThenRerun()` runs it inline). That turns a search for a username
     * nobody has into seconds of latency for no possible result.
     *
     * @var list<string>
     */
    private const LOCAL_ONLY_TYPES = ['user', 'genre', 'playlist'];

    private function shouldSyncFromProvider(SearchQuery $query): bool
    {
        /*
         | The opt-in gate, and it comes first because it is the cheapest check
         | and the one that matters most.
         |
         | Every search used to qualify, which was correct reasoning about
         | *repeated* searches and completely wrong about typing. A type-ahead
         | field issues a search per keystroke pause, and each prefix — "a",
         | "ar", "ari", "arij" — is a distinct term with its own debounce key,
         | so the 15-minute debounce below never saw the same term twice and
         | never suppressed anything. Typing one artist name cost five separate
         | provider searches, roughly seventy-five outbound requests, to answer
         | a question the user had not finished asking.
         |
         | Now the client says whether it means it. The results page sets
         | `sync=1`; the suggestion dropdown does not, and is answered from the
         | local catalog at zero provider cost.
         */
        if (! $query->sync) {
            return false;
        }

        if ($query->type !== null && in_array($query->type, self::LOCAL_ONLY_TYPES, true)) {
            return false;
        }

        /*
         | Checked before the debounce is claimed, not after, and that ordering
         | is the point. A provider parked after a 429 can stay parked for the
         | rest of the day; claiming the slot anyway would mean every term
         | searched during the outage is locked out of syncing for 15 minutes
         | *after* the provider comes back, having never actually asked it
         | anything. Nothing to gain from the sync, so nothing is spent on it.
         */
        if ($this->providers->available() === []) {
            return false;
        }

        $debounceKey = $this->debounceKey($query);

        if (Cache::has($debounceKey)) {
            return false;
        }

        $debounceMinutes = max(1, (int) config('providers.sync.lazy_debounce_minutes', 15));
        Cache::put($debounceKey, true, $debounceMinutes * 60);

        return true;
    }

    /**
     * The claim that says "this term has already been put to the provider
     * recently". Shared by the claim and its refund below, so the two can never
     * drift apart into a slot that is spent but unreleasable.
     */
    private function debounceKey(SearchQuery $query): string
    {
        return implode(':', [
            'providers', 'lazy_sync_debounce', $query->type ?? 'all',
            hash('xxh128', mb_strtolower($query->term)),
        ]);
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
             |
             | The background half is dispatched as two typed jobs rather than
             | one untyped one: an untyped job searches songs as well, which the
             | inline call directly above has just done. The provider charges us
             | for that repeat even though SyncService's checksum then discards
             | every row of it — half the outbound traffic of a grouped search,
             | spent on an answer already in hand.
             */
            LazySyncSearchJob::dispatchSync($query->term, 'song', cacheKey: $query->cacheKey());
            LazySyncSearchJob::dispatch($query->term, 'artist', cacheKey: $query->cacheKey());
            LazySyncSearchJob::dispatch($query->term, 'album', cacheKey: $query->cacheKey());
        } else {
            LazySyncSearchJob::dispatchSync($query->term, $query->type, cacheKey: $query->cacheKey());
        }

        if (! $this->liveSyncAvailable()) {
            /*
             | The provider went away during the sync we just ran — this search
             | is the one that discovered it. Give the debounce slot back: it is
             | a record of having *asked* the provider about this term, and we
             | got no answer, so nothing was learned that a repeat would
             | duplicate. Keeping it would leave this term — usually a popular
             | one, since it is being searched right now — unable to resync for
             | the next quarter of an hour after the provider recovers.
             |
             | Every other term is already protected: shouldSyncFromProvider()
             | refuses to claim a slot at all once the provider is known to be
             | parked. This covers only the first one through the door.
             */
            Cache::forget($this->debounceKey($query));
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
