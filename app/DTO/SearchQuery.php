<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\SortOrder;
use App\Search\LanguageTermExtractor;
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
     * @param  bool  $sync  Whether this search may spend provider requests before answering.
     *                      Opt-in, and it has to be: a type-ahead field issues a search per
     *                      keystroke pause, and every prefix of a word ("a", "ar", "ari") is a
     *                      *different* term, so the per-term debounce that protects a repeated
     *                      search protects nothing here — it never sees the same term twice.
     *                      Typing one artist name was costing five provider searches. Only a
     *                      deliberate search — Enter pressed, results page opened — sets this.
     */
    public function __construct(
        public string $term,
        public ?string $type = null,
        public int $page = 1,
        public int $limit = 20,
        public array $filters = [],
        public SortOrder $sort = SortOrder::Relevance,
        public bool $sync = false,
    ) {}

    /**
     * A language named in the query text becomes a filter here, before anything
     * downstream sees the term.
     *
     * Here rather than in the engine so that every consumer agrees on one
     * already-qualified query: `cacheKey()` below covers the shortened term
     * *and* the language it yielded, so "… in hindi" and "…" cache separately
     * instead of one serving the other's results; and `LazySyncSearchJob` asks
     * the provider for the title alone, since JioSaavn makes no more sense of
     * a trailing "in hindi" than MySQL's FULLTEXT index did.
     *
     * See `LanguageTermExtractor` for why only a trailing word counts.
     */
    public static function fromRequest(Request $request): self
    {
        $maxLimit = (int) config('music.pagination.max_limit');

        $parsed = LanguageTermExtractor::extract(trim((string) $request->query('q', '')));

        return new self(
            term: $parsed['term'],
            type: $request->query('type') !== null ? (string) $request->query('type') : null,
            page: max(1, (int) $request->query('page', 1)),
            limit: min($maxLimit, max(1, (int) $request->query(
                'limit',
                (string) config('music.pagination.default_limit')
            ))),
            sync: $request->boolean('sync'),
            filters: array_filter([
                'genre' => $request->query('genre'),
                /*
                 | An explicit `?language=` always wins: that is a deliberate
                 | filter from the UI, while the parsed one is an inference off
                 | free text and has no business overriding it.
                 */
                'language' => $request->query('language') ?? $parsed['language'],
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
