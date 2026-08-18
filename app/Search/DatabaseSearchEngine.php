<?php

declare(strict_types=1);

namespace App\Search;

use App\Contracts\Search\SearchEngine;
use App\DTO\SearchQuery;
use App\DTO\SearchResults;
use App\Enums\PlaylistVisibility;
use App\Enums\SortOrder;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Search backed by MySQL FULLTEXT indexes.
 *
 * This is what runs locally and it is deliberately not a full substitute for
 * Elasticsearch — no synonyms, no per-language analyzers, no relevance
 * tuning beyond "exact match, then popularity". See docs/DEFERRED.md §2 for
 * what changes when Elasticsearch lands.
 *
 * It does tolerate a misspelling, but only as a fallback: `fuzzyMatch()`
 * only runs once the indexed FULLTEXT/LIKE pass has already come back with
 * nothing, scoring a popularity-bounded candidate set by edit-distance
 * similarity in PHP. See that method's docblock for why FULLTEXT's own
 * prefix wildcard isn't enough on its own.
 */
final class DatabaseSearchEngine implements SearchEngine
{
    /**
     * Which column each searchable type matches on, and what to eager-load so
     * results can be serialised without an N+1.
     *
     * `credit` names a belongsTo relation whose own name is searched as well as
     * the type's own column. Songs and albums have one because the overwhelming
     * majority of real queries against this catalog are artist names, and a
     * title-only match answers those almost not at all: "pritam" matched four
     * songs locally while the provider — which searches credits — returns over
     * a thousand, every one of them already sitting in our own `songs` table
     * under a title that does not contain the word "pritam". Storing the
     * catalog is only half of "search returns everything"; being able to find
     * it by the name people actually type is the other half.
     *
     * @var array<string, array{model: class-string<Model>, column: string, with: list<string>, credit?: string}>
     */
    private const TYPES = [
        'song' => ['model' => Song::class, 'column' => 'title', 'with' => ['artist', 'album', 'genre', 'language'], 'credit' => 'artist'],
        'artist' => ['model' => Artist::class, 'column' => 'name', 'with' => []],
        'album' => ['model' => Album::class, 'column' => 'title', 'with' => ['artist', 'language'], 'credit' => 'artist'],
        'playlist' => ['model' => Playlist::class, 'column' => 'title', 'with' => ['owner']],
        'user' => ['model' => User::class, 'column' => 'name', 'with' => []],
        'genre' => ['model' => Genre::class, 'column' => 'name', 'with' => []],
    ];

    /**
     * Types with no popularity signal of their own, and what to rank them by
     * instead.
     *
     * Ordering has to name a real column — `fuzzyMatch()` bounds its candidate
     * scan with it and `applySort()` breaks relevance ties with it — so a type
     * without one needs a deliberate substitute rather than a null that every
     * call site then has to handle. A profile ranks by how many followers it
     * has; a genre by how much of the catalog sits under it. Both are the
     * closest honest analogue of "popular" those tables have.
     *
     * @var array<string, string>
     */
    private const POPULARITY_SUBSTITUTES = [
        'playlist' => 'tracks_count',
        'user' => 'followers_count',
        'genre' => 'songs_count',
    ];

    /**
     * Counts that have to be selected before `POPULARITY_SUBSTITUTES` can order
     * by them — they are aggregates, not columns.
     *
     * @var array<string, string>
     */
    private const POPULARITY_COUNTS = [
        'user' => 'followers',
        'genre' => 'songs',
    ];

    /**
     * Minimum edit-distance similarity (see `similarity()`) for a fuzzy
     * candidate to surface at all. Tuned against real typos this catalog
     * needs to survive: "Arjit Singh" → "Arijit Singh" (0.92), "Arijeet
     * Singh" → "Arijit Singh" (0.85), "Gali Gaali" → "Gali Gali" (0.90) all
     * clear it comfortably; unrelated terms score well below it.
     */
    private const FUZZY_THRESHOLD = 0.6;

    public function searchAll(SearchQuery $query): SearchResults
    {
        if (! $query->hasTerm()) {
            return SearchResults::empty();
        }

        $perType = (int) config('search.limits.per_type');

        /** @var array<string, Collection<int, Model>> $results */
        $results = [];

        foreach (array_keys(self::TYPES) as $type) {
            $results[$type] = $this->buildQuery($type, $query)->limit($perType)->get();
        }

        /*
         | Typo tolerance is decided for the search as a whole, not per type.
         |
         | Per type, it fired on nearly every successful search: a real query
         | matches songs and albums and nothing else, so `artist`, `playlist`,
         | `user` and `genre` each came back empty and each paid for a
         | thousand-row scan scored with PHP `levenshtein` — measured at ~390 ms
         | for artists alone, on a search that had already found what the
         | listener asked for.
         |
         | If any type matched, the term is spelled correctly and there is no
         | typo to forgive. That is `needsFuzzyFallback()`'s own stated
         | reasoning ("once FULLTEXT has found something…"); this applies it at
         | the level the term actually lives at.
         */
        $found = array_sum(array_map(static fn (Collection $rows): int => $rows->count(), $results));

        if ($this->needsFuzzyFallback($found, $query->term)) {
            foreach (array_keys(self::TYPES) as $type) {
                $results[$type] = $this->fuzzyMatch($type, $query, $perType);
            }
        }

        return new SearchResults(
            songs: $results['song'],
            artists: $results['artist'],
            albums: $results['album'],
            playlists: $results['playlist'],
            users: $results['user'],
            genres: $results['genre'],
        );
    }

    public function searchType(SearchQuery $query): LengthAwarePaginator
    {
        if ($query->type === null || ! isset(self::TYPES[$query->type])) {
            throw new InvalidArgumentException("Unsupported search type [{$query->type}].");
        }

        if (! $query->hasTerm()) {
            return new Paginator([], 0, $query->limit, $query->page);
        }

        $paginator = $this->buildQuery($query->type, $query)
            ->paginate(perPage: $query->limit, page: $query->page);

        if (! $this->needsFuzzyFallback($paginator->total(), $query->term)) {
            return $paginator;
        }

        // A typo'd query rarely needs more than a couple of pages of
        // near-matches — this caps the candidate pool without a second,
        // unbounded fuzzy pass for every page the user scrolls to.
        $fuzzy = $this->fuzzyMatch($query->type, $query, max($query->limit * 3, 30));

        return new Paginator(
            $fuzzy->forPage($query->page, $query->limit)->values(),
            $fuzzy->count(),
            $query->limit,
            $query->page,
        );
    }

    public function suggest(string $term, int $limit): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return new Collection;
        }

        /*
         | Suggestions come from song and artist names only. Album and playlist
         | titles are noisier and push the useful completions out of a 10-row
         | dropdown.
         */
        $songs = $this->matchText(Song::query(), 'songs', 'title', $term)
            ->orderByDesc('popularity')
            ->limit($limit)
            ->pluck('title');

        $artists = $this->matchText(Artist::query(), 'artists', 'name', $term)
            ->orderByDesc('popularity')
            ->limit($limit)
            ->pluck('name');

        return $songs->concat($artists)
            ->unique()
            ->values()
            ->take($limit);
    }

    /**
     * @return Builder<Model>
     */
    private function buildQuery(string $type, SearchQuery $query): Builder
    {
        $config = self::TYPES[$type];
        $model = $config['model'];

        /** @var Builder<Model> $builder */
        $builder = $model::query()->with($config['with']);

        $table = (new $model)->getTable();

        $this->matchText($builder, $table, $config['column'], $query->term, $config['credit'] ?? null);
        $this->withPopularityCount($builder, $type);
        $this->applyFilters($builder, $type, $query);
        $this->applySort($builder, $type, $query);

        return $builder;
    }

    /**
     * Select the aggregate a type ranks by, when its "popularity" is a count
     * rather than a column.
     *
     * Has to happen before `applySort()`: ordering by `followers_count` is only
     * legal once `withCount()` has put it in the select list. The resource
     * layer reads the same aggregate through `whenCounted()`, so this doubles
     * as the eager-load that keeps the serialiser off an N+1.
     *
     * @param  Builder<Model>  $builder
     */
    private function withPopularityCount(Builder $builder, string $type): void
    {
        $relation = self::POPULARITY_COUNTS[$type] ?? null;

        if ($relation !== null) {
            $builder->withCount($relation);
        }
    }

    /**
     * Applies the text predicate and exposes a `relevance` column for ordering.
     *
     * @param  Builder<Model>  $builder
     * @return Builder<Model>
     */
    private function matchText(Builder $builder, string $table, string $column, string $term, ?string $credit = null): Builder
    {
        $minLength = (int) config('search.drivers.database.min_fulltext_term_length');
        $booleanTerm = $this->toBooleanTerm($term);

        /*
         | MySQL drops tokens shorter than innodb_ft_min_token_size from the
         | FULLTEXT index entirely, so a 2-character query would match nothing.
         | Fall back to a prefix LIKE so autocomplete on "Be" still works.
         */
        if ($booleanTerm === '' || mb_strlen($term) < $minLength) {
            $like = $this->escapeLike($term).'%';

            return $builder
                ->where(function (Builder $query) use ($column, $like, $credit): void {
                    $query->where($column, 'like', $like);

                    if ($credit !== null) {
                        $query->orWhereHas($credit, fn (Builder $related) => $related->where('name', 'like', $like));
                    }
                })
                ->selectRaw("{$table}.*, 0 as relevance");
        }

        if ($credit === null) {
            return $builder
                ->whereRaw("MATCH({$table}.{$column}) AGAINST (? IN BOOLEAN MODE)", [$booleanTerm])
                ->selectRaw(
                    "{$table}.*, MATCH({$table}.{$column}) AGAINST (? IN BOOLEAN MODE) as relevance",
                    [$booleanTerm]
                );
        }

        return $builder
            ->joinSub(
                $this->creditAwareMatches($builder, $table, $column, $booleanTerm, $credit),
                'search_match',
                static fn (JoinClause $join) => $join->on('search_match.id', '=', "{$table}.id"),
            )
            /*
             | Relevance stays the *title* score even when the row was reached
             | by its credit, which scores 0 and therefore sorts below every
             | title match. That ordering is deliberate: someone typing "pritam"
             | wants the song actually called "Pritam" first and his thousand
             | compositions after it, not the other way round. `applySort()`'s
             | secondary key (popularity) orders the credit matches among
             | themselves.
             */
            ->selectRaw("{$table}.*, search_match.relevance as relevance");
    }

    /**
     * The set of rows matching either the title or the credited artist, as a
     * joinable derived table.
     *
     * ## Why this is not a `WHERE MATCH(...) OR EXISTS (...)`
     *
     * It was, and that single `OR` was the slowest thing in the application.
     * MySQL cannot use a FULLTEXT index for a `MATCH()` that is one side of an
     * `OR`, so it fell back to scanning every row of `songs` and running the
     * correlated subquery — its own FULLTEXT lookup — once per row. Measured on
     * this catalog (14.3k songs, 8.4k artists):
     *
     * | query                        | before  | after  |
     * |------------------------------|---------|--------|
     * | "Pritam", first page         | 723 ms  | 26 ms  |
     * | "Pritam", paginator COUNT    | 3938 ms | 41 ms  |
     * | "Arijit Singh", COUNT        | 596 ms  | 27 ms  |
     *
     * Both branches below are independently indexable, which is the entire
     * point: each is a plain `MATCH()` against one table, and the join then
     * narrows `songs` by primary key. Row totals were verified identical
     * (499 and 426 respectively), so this is a plan change and not a
     * behaviour change.
     *
     * ## Why UNION ALL + MAX rather than UNION
     *
     * A row whose title *and* artist both match appears in both branches with
     * different scores, so a plain `UNION` (which dedupes on the whole row,
     * relevance included) keeps both and the join emits that row twice —
     * confirmed on real data: "Arijit Singh" returned 442 rows for 427 distinct
     * songs. Grouping by id and taking `MAX(relevance)` collapses them and
     * picks the title score over the credit branch's zero, which is exactly the
     * ranking the caller documents above.
     *
     * The join also replaces the old comment's reason for preferring a
     * subquery: nothing from `artists` enters the outer scope here either, so
     * `applyFilters()` and `applySort()` still see one unambiguous set of
     * columns.
     */
    private function creditAwareMatches(Builder $builder, string $table, string $column, string $booleanTerm, string $credit): QueryBuilder
    {
        /*
         | Both read off the relation rather than hardcoded, so a future credit
         | that is not an artist still builds a valid query. The column is
         | `name` for every credit type this catalog has.
         */
        $relation = $builder->getModel()->{$credit}();
        $creditTable = $relation->getModel()->getTable();
        $foreignKey = $relation->getForeignKeyName();

        $byTitle = DB::table($table)
            ->selectRaw(
                "{$table}.id as id, MATCH({$table}.{$column}) AGAINST (? IN BOOLEAN MODE) as relevance",
                [$booleanTerm]
            )
            ->whereRaw("MATCH({$table}.{$column}) AGAINST (? IN BOOLEAN MODE)", [$booleanTerm]);

        $byCredit = DB::table($table)
            ->selectRaw("{$table}.id as id, 0 as relevance")
            ->whereIn("{$table}.{$foreignKey}", static fn (QueryBuilder $sub) => $sub
                ->select("{$creditTable}.id")
                ->from($creditTable)
                ->whereRaw("MATCH({$creditTable}.name) AGAINST (? IN BOOLEAN MODE)", [$booleanTerm]));

        return DB::query()
            ->fromSub($byTitle->unionAll($byCredit), 'u')
            ->selectRaw('u.id as id, MAX(u.relevance) as relevance')
            ->groupBy('u.id');
    }

    /**
     * Turns user input into a safe BOOLEAN MODE expression.
     *
     * Every operator character is stripped rather than escaped — leaving any of
     * them in lets a user write a query that errors or that silently inverts the
     * search (a stray `-` excludes instead of includes).
     */
    /**
     * MySQL/InnoDB's built-in FULLTEXT stopword list
     * (`INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD`). These are never
     * written to the index, so marking one `+` (required) makes the whole
     * boolean query unsatisfiable — "Die With A Smile" would otherwise match
     * nothing at all, because "with" and "a" can never be found in the index
     * regardless of how many rows actually contain them.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for',
        'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the',
        'this', 'to', 'und', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'www',
    ];

    private function toBooleanTerm(string $term): string
    {
        $cleaned = preg_replace('/[+\-><()~*"@\\\\]+/u', ' ', $term) ?? '';

        $words = array_filter(
            preg_split('/\s+/u', trim($cleaned)) ?: [],
            static fn (string $word): bool => $word !== ''
        );

        // Stopwords are dropped rather than left optional: they were never
        // written to the index, so requiring one is fatal to the whole query
        // and leaving it optional would contribute nothing either way.
        $significant = array_values(array_filter(
            $words,
            static fn (string $word): bool => ! in_array(mb_strtolower($word), self::STOPWORDS, true)
        ));

        // A query that is entirely stopwords ("a the") has nothing significant
        // left to match on; matchText() falls back to a LIKE prefix on the raw
        // term in that case.
        if ($significant === []) {
            return '';
        }

        // `+word*` = every remaining word required, each matching as a prefix.
        return implode(' ', array_map(
            static fn (string $word): string => '+'.$word.'*',
            $significant
        ));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * A fuzzy retry is only worth its extra query once the indexed path has
     * already come back with nothing — once FULLTEXT has found something,
     * layering typo-tolerance on top of an already-working result set is a
     * refinement for later, not what breaks a user's search today.
     */
    private function needsFuzzyFallback(int $strictCount, string $term): bool
    {
        return $strictCount === 0 && mb_strlen(trim($term)) >= 2;
    }

    /**
     * The retry when FULLTEXT/LIKE found nothing at all.
     *
     * `toBooleanTerm()`'s `+word*` only forgives a *missing trailing*
     * character, and only by accident: "Blu Halo" happens to match "Blue
     * Halo" because "Blu" is a valid prefix of "Blue", but "Arjit Singh"
     * (a dropped middle letter) and "Gali Gaali" (an extra one) return
     * nothing, because neither is a prefix of the correct spelling. A
     * transposed, wrong, extra, or missing letter *anywhere* in the word is
     * exactly the class of typo Spotify's own search visibly tolerates and
     * FULLTEXT structurally cannot.
     *
     * This scores every candidate by edit-distance similarity in PHP
     * instead. The catalog is small enough (low thousands of rows per type)
     * that a popularity-bounded scan and score is milliseconds of work, and
     * it only ever runs after the indexed path has already come back empty
     * — a real typo is the rare case, not the common one.
     *
     * @return Collection<int, Model>
     */
    private function fuzzyMatch(string $type, SearchQuery $query, int $limit): Collection
    {
        $config = self::TYPES[$type];
        $model = $config['model'];
        $column = $config['column'];
        $popularityColumn = $this->popularityColumn($type);

        $needle = $this->normalizeForFuzzy($query->term);

        if ($needle === '') {
            return new Collection;
        }

        /** @var Builder<Model> $builder */
        $builder = $model::query()->with($config['with']);
        $this->withPopularityCount($builder, $type);
        $this->applyFilters($builder, $type, $query);

        // Bounded by popularity, not a full table scan: an obscure,
        // rarely-searched row losing out to a popular near-miss is the
        // right trade against scoring every row in PHP on every miss.
        $candidates = $builder->orderByDesc($popularityColumn)->limit(1000)->get();

        $candidates->each(function (Model $candidate) use ($column, $needle) {
            $label = $this->normalizeForFuzzy((string) $candidate->getAttribute($column));
            $candidate->setAttribute('relevance', $this->similarity($needle, $label));
        });

        return $candidates
            ->filter(fn (Model $candidate) => $candidate->getAttribute('relevance') >= self::FUZZY_THRESHOLD)
            ->sortBy([['relevance', 'desc'], [$popularityColumn, 'desc']])
            ->take($limit)
            ->values();
    }

    /** Matches `applySort()`'s own popularity column choice per type. */
    private function popularityColumn(string $type): string
    {
        return self::POPULARITY_SUBSTITUTES[$type] ?? 'popularity';
    }

    /**
     * Lowercased, punctuation-collapsed-to-spaces, whitespace-normalised —
     * so "Dhurandhar," and "dhurandhar" (or a title carrying a stray
     * double space) compare as equal rather than merely similar.
     */
    private function normalizeForFuzzy(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * 0..1 similarity — 1 is identical, 0 shares nothing. Threshold-checked
     * against `FUZZY_THRESHOLD` by the caller.
     */
    private function similarity(string $needle, string $haystack): float
    {
        if ($needle === '' || $haystack === '') {
            return 0.0;
        }

        $lengthA = mb_strlen($needle);
        $lengthB = mb_strlen($haystack);
        $longest = max($lengthA, $lengthB);

        /*
         | Reject on length alone before running the matrix.
         |
         | Edit distance is at least the difference in length, and the caller
         | keeps nothing scoring below `FUZZY_THRESHOLD`, so
         | `abs($lengthA - $lengthB) > $longest * (1 - threshold)` proves this
         | pair can never survive the filter — without the O(n·m) work that
         | would prove it precisely. Most of a thousand popularity-ranked
         | candidates are nowhere near the needle's length, which is why this
         | short-circuit carries the fuzzy path rather than shaving it: the
         | rows it skips are exactly the rows that were going to be discarded.
         |
         | Returns 0.0 rather than a real score, which is safe *because* the
         | pair is already known to be below the threshold — nothing
         | downstream distinguishes one rejected score from another.
         */
        if (abs($lengthA - $lengthB) > $longest * (1 - self::FUZZY_THRESHOLD)) {
            return 0.0;
        }

        return 1 - ($this->levenshtein($needle, $haystack, $lengthA, $lengthB) / $longest);
    }

    /**
     * Edit distance, taking PHP's C implementation whenever it is safe to.
     *
     * `levenshtein()` counts bytes, so it overcounts every multi-byte
     * character — the reason `levenshteinMb()` below exists. But when both
     * strings are single-byte throughout, bytes *are* characters and the two
     * agree exactly, so the native call is free correctness. That covers the
     * transliterated Latin titles that make up most of this catalog; Devanagari
     * and every other script still take the accurate path.
     *
     * `strlen() === mb_strlen()` is the whole test: they can only agree when no
     * character occupies more than one byte.
     */
    private function levenshtein(string $a, string $b, int $lengthA, int $lengthB): int
    {
        if (strlen($a) === $lengthA && strlen($b) === $lengthB) {
            return levenshtein($a, $b);
        }

        return $this->levenshteinMb($a, $b);
    }

    /**
     * PHP's built-in `levenshtein()` operates byte-by-byte, which overcounts
     * edits on any multi-byte character — every non-Latin script this
     * catalog's languages actually use (06_SEARCH_ARCHITECTURE §7). This is
     * the same algorithm over `mb_str_split()` instead, so a single
     * Devanagari character costs one edit, not three or four.
     */
    private function levenshteinMb(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);

        $lengthB = count($b);
        $previousRow = range(0, $lengthB);

        foreach ($a as $i => $charA) {
            $currentRow = [$i + 1];

            foreach ($b as $j => $charB) {
                $currentRow[$j + 1] = min(
                    $currentRow[$j] + 1,
                    $previousRow[$j + 1] + 1,
                    $previousRow[$j] + ($charA === $charB ? 0 : 1),
                );
            }

            $previousRow = $currentRow;
        }

        return $previousRow[$lengthB];
    }

    /**
     * @param  Builder<Model>  $builder
     */
    private function applyFilters(Builder $builder, string $type, SearchQuery $query): Builder
    {
        if ($type === 'playlist') {
            /*
             | Search only ever exposes public playlists. Unlisted ones are
             | reachable by direct link but must stay out of result sets, and
             | private ones belong to the owner's library, not to search.
             */
            return $builder->where('visibility', PlaylistVisibility::Public);
        }

        /*
         | Profiles and genres share none of the catalog's filter vocabulary —
         | a genre has no release year and a user has no artist to filter
         | through. Returning early is not just an optimisation: `country`
         | below falls through to `whereHas('artist')` for any unrecognised
         | type, which would throw on both of these.
         */
        if (in_array($type, ['user', 'genre'], true)) {
            return $builder;
        }

        $genre = $query->filter('genre');
        if ($genre !== null && in_array($type, ['song'], true)) {
            $builder->whereHas('genre', fn (Builder $q) => $q->where('slug', $genre)->orWhere('name', $genre));
        }

        $language = $query->filter('language');
        if ($language !== null && in_array($type, ['song', 'album'], true)) {
            $builder->whereHas('language', fn (Builder $q) => $q->where('code', $language)->orWhere('name', $language));
        }

        $country = $query->filter('country');
        if ($country !== null) {
            $type === 'artist'
                ? $builder->where('country', $country)
                : $builder->whereHas('artist', fn (Builder $q) => $q->where('country', $country));
        }

        $year = $query->filter('release_year');
        if ($year !== null && in_array($type, ['song', 'album'], true)) {
            $builder->whereYear('release_date', (int) $year);
        }

        $minPopularity = $query->filter('min_popularity');
        if ($minPopularity !== null) {
            $builder->where('popularity', '>=', (int) $minPopularity);
        }

        if ($type === 'song') {
            $minDuration = $query->filter('min_duration');
            if ($minDuration !== null) {
                $builder->where('duration', '>=', (int) $minDuration);
            }

            $maxDuration = $query->filter('max_duration');
            if ($maxDuration !== null) {
                $builder->where('duration', '<=', (int) $maxDuration);
            }
        }

        return $builder;
    }

    /**
     * @param  Builder<Model>  $builder
     */
    private function applySort(Builder $builder, string $type, SearchQuery $query): Builder
    {
        $titleColumn = self::TYPES[$type]['column'];
        $hasReleaseDate = in_array($type, ['song', 'album'], true);

        return match ($query->sort) {
            SortOrder::Newest => $hasReleaseDate
                ? $builder->orderByDesc('release_date')
                : $builder->orderByDesc('created_at'),
            SortOrder::Oldest => $hasReleaseDate
                ? $builder->orderBy('release_date')
                : $builder->orderBy('created_at'),
            SortOrder::Popularity => $builder->orderByDesc($this->popularityColumn($type)),
            SortOrder::Alphabetical => $builder->orderBy($titleColumn),
            /*
             | Relevance alone clusters ties arbitrarily, so popularity breaks
             | them — this is the "exact match, then popularity" ranking from
             | 06_SEARCH_ARCHITECTURE §8.
             */
            SortOrder::Relevance => $builder
                ->orderByDesc('relevance')
                ->orderByDesc($this->popularityColumn($type)),
        };
    }
}
