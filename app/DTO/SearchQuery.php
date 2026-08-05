<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\SortOrder;
use Illuminate\Http\Request;

/**
 * An immutable, already-validated description of a search request.
 *
 * Search engines receive this instead of a Request so that the engine layer has
 * no dependency on HTTP and can be driven from jobs and tests.
 */
final readonly class SearchQuery
{
    /**
     * @param  string|null  $type  One of the keys in config('search.types'); null searches everything.
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $term,
        public ?string $type = null,
        public int $page = 1,
        public int $limit = 20,
        public array $filters = [],
        public SortOrder $sort = SortOrder::Relevance,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $maxLimit = (int) config('music.pagination.max_limit');

        return new self(
            term: trim((string) $request->query('q', '')),
            type: $request->query('type') !== null ? (string) $request->query('type') : null,
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
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            sort: SortOrder::tryFrom((string) $request->query('sort', '')) ?? SortOrder::Relevance,
        );
    }

    public function filter(string $key): mixed
    {
        return $this->filters[$key] ?? null;
    }

    public function hasTerm(): bool
    {
        return $this->term !== '';
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /**
     * Stable cache key. Filters are sorted so that `?genre=rock&language=en`
     * and `?language=en&genre=rock` share one cache entry.
     */
    public function cacheKey(): string
    {
        $filters = $this->filters;
        ksort($filters);

        return 'search:'.hash('xxh128', serialize([
            'term' => mb_strtolower($this->term),
            'type' => $this->type,
            'page' => $this->page,
            'limit' => $this->limit,
            'filters' => $filters,
            'sort' => $this->sort->value,
        ]));
    }
}
