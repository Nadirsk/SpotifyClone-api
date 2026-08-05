<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\SortOrder;
use Illuminate\Http\Request;

/**
 * Filters, sorting and pagination for a catalog listing endpoint
 * (GET /songs, /artists, /albums).
 *
 * Distinct from SearchQuery: there is no search term here, and the default sort
 * is popularity rather than relevance because relevance is meaningless without
 * a term.
 */
final readonly class CatalogQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public int $page = 1,
        public int $limit = 20,
        public array $filters = [],
        public SortOrder $sort = SortOrder::Popularity,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $maxLimit = (int) config('music.pagination.max_limit');

        return new self(
            page: max(1, (int) $request->query('page', 1)),
            limit: min($maxLimit, max(1, (int) $request->query(
                'limit',
                (string) config('music.pagination.default_limit')
            ))),
            filters: array_filter([
                'genre' => $request->query('genre'),
                'language' => $request->query('language'),
                'country' => $request->query('country'),
                'release_year' => $request->query('release_year'),
                'min_popularity' => $request->query('popularity'),
                'min_duration' => $request->query('min_duration'),
                'max_duration' => $request->query('max_duration'),
                'artist_id' => $request->query('artist_id'),
                'album_id' => $request->query('album_id'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            sort: SortOrder::tryFrom((string) $request->query('sort', '')) ?? SortOrder::Popularity,
        );
    }

    public function filter(string $key): mixed
    {
        return $this->filters[$key] ?? null;
    }

    /**
     * Stable cache key fragment; filters sorted so equivalent queries collide.
     */
    public function cacheKey(string $prefix): string
    {
        $filters = $this->filters;
        ksort($filters);

        return $prefix.':'.hash('xxh128', serialize([
            'page' => $this->page,
            'limit' => $this->limit,
            'filters' => $filters,
            'sort' => $this->sort->value,
        ]));
    }
}
