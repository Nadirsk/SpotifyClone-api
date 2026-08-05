<?php

declare(strict_types=1);

namespace App\Contracts\Search;

use App\DTO\SearchQuery;
use App\DTO\SearchResults;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The only search contract the application layer is allowed to depend on.
 *
 * Two implementations are planned: DatabaseSearchEngine (MySQL FULLTEXT, in use
 * today) and an Elasticsearch engine for production. Swapping between them must
 * be a config change and nothing else — see docs/DEFERRED.md.
 */
interface SearchEngine
{
    /**
     * Search every type at once, capping each at config('search.limits.per_type').
     * Used by the global search bar.
     */
    public function searchAll(SearchQuery $query): SearchResults;

    /**
     * Paginated search within a single type. $query->type must be set.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function searchType(SearchQuery $query): LengthAwarePaginator;

    /**
     * Lightweight prefix suggestions for the autocomplete dropdown.
     *
     * @return Collection<int, string>
     */
    public function suggest(string $term, int $limit): Collection;
}
