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
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
        private readonly CacheRepository $rawCache,
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

        if ($resultsCount === 0) {
            $this->dispatchLazySync($query);
        }
    }

    /**
     * A local miss (06_SEARCH_ARCHITECTURE §2: "Database Miss → Query
     * Providers"). Queued rather than awaited — the response the user is
     * waiting on has already been built, so nothing here may block it.
     *
     * Debounced per (term, type) via Cache::add(), which is atomic: two
     * requests racing for the same missing term dispatch exactly one job, not
     * because of a lock, but because only the first add() succeeds. This
     * bypasses CacheService deliberately — it is a dispatch lock, not a
     * domain-data cache, so it has no bucket in config/music.php.
     */
    private function dispatchLazySync(SearchQuery $query): void
    {
        $debounceMinutes = max(1, (int) config('providers.sync.lazy_debounce_minutes', 15));
        $key = 'lazy_sync:dispatched:'.hash('xxh128', mb_strtolower($query->term).'|'.($query->type ?? 'all'));

        if (! $this->rawCache->add($key, true, now()->addMinutes($debounceMinutes))) {
            return;
        }

        LazySyncSearchJob::dispatch($query->term, $query->type);
    }

    private function resolveListLimit(?int $limit): int
    {
        $max = (int) config('music.pagination.max_limit');
        $default = (int) config('search.limits.autocomplete');

        return min($max, max(1, $limit ?? $default));
    }
}
