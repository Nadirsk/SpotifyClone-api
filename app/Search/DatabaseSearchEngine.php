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
use App\Models\Playlist;
use App\Models\Song;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Search backed by MySQL FULLTEXT indexes.
 *
 * This is what runs locally and it is deliberately not a full substitute for
 * Elasticsearch — no real typo tolerance, no synonyms, no per-language
 * analyzers. See docs/DEFERRED.md §2 for what changes when Elasticsearch lands.
 */
final class DatabaseSearchEngine implements SearchEngine
{
    /**
     * Which column each searchable type matches on, and what to eager-load so
     * results can be serialised without an N+1.
     *
     * @var array<string, array{model: class-string<Model>, column: string, with: list<string>}>
     */
    private const TYPES = [
        'song' => ['model' => Song::class, 'column' => 'title', 'with' => ['artist', 'album', 'genre', 'language']],
        'artist' => ['model' => Artist::class, 'column' => 'name', 'with' => []],
        'album' => ['model' => Album::class, 'column' => 'title', 'with' => ['artist', 'language']],
        'playlist' => ['model' => Playlist::class, 'column' => 'title', 'with' => ['owner']],
    ];

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

        return new SearchResults(
            songs: $results['song'],
            artists: $results['artist'],
            albums: $results['album'],
            playlists: $results['playlist'],
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

        return $this->buildQuery($query->type, $query)
            ->paginate(perPage: $query->limit, page: $query->page);
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

        $this->matchText($builder, $table, $config['column'], $query->term);
        $this->applyFilters($builder, $type, $query);
        $this->applySort($builder, $type, $query);

        return $builder;
    }

    /**
     * Applies the text predicate and exposes a `relevance` column for ordering.
     *
     * @param  Builder<Model>  $builder
     * @return Builder<Model>
     */
    private function matchText(Builder $builder, string $table, string $column, string $term): Builder
    {
        $minLength = (int) config('search.drivers.database.min_fulltext_term_length');
        $booleanTerm = $this->toBooleanTerm($term);

        /*
         | MySQL drops tokens shorter than innodb_ft_min_token_size from the
         | FULLTEXT index entirely, so a 2-character query would match nothing.
         | Fall back to a prefix LIKE so autocomplete on "Be" still works.
         */
        if ($booleanTerm === '' || mb_strlen($term) < $minLength) {
            return $builder
                ->where($column, 'like', $this->escapeLike($term).'%')
                ->selectRaw("{$table}.*, 0 as relevance");
        }

        return $builder
            ->whereRaw("MATCH({$table}.{$column}) AGAINST (? IN BOOLEAN MODE)", [$booleanTerm])
            ->selectRaw(
                "{$table}.*, MATCH({$table}.{$column}) AGAINST (? IN BOOLEAN MODE) as relevance",
                [$booleanTerm]
            );
    }

    /**
     * Turns user input into a safe BOOLEAN MODE expression.
     *
     * Every operator character is stripped rather than escaped — leaving any of
     * them in lets a user write a query that errors or that silently inverts the
     * search (a stray `-` excludes instead of includes).
     */
    private function toBooleanTerm(string $term): string
    {
        $cleaned = preg_replace('/[+\-><()~*"@\\\\]+/u', ' ', $term) ?? '';

        $words = array_filter(
            preg_split('/\s+/u', trim($cleaned)) ?: [],
            static fn (string $word): bool => $word !== ''
        );

        if ($words === []) {
            return '';
        }

        // `+word*` = every word required, each matching as a prefix.
        return implode(' ', array_map(
            static fn (string $word): string => '+'.$word.'*',
            $words
        ));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
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
            SortOrder::Popularity => $type === 'playlist'
                ? $builder->orderByDesc('tracks_count')
                : $builder->orderByDesc('popularity'),
            SortOrder::Alphabetical => $builder->orderBy($titleColumn),
            /*
             | Relevance alone clusters ties arbitrarily, so popularity breaks
             | them — this is the "exact match, then popularity" ranking from
             | 06_SEARCH_ARCHITECTURE §8.
             */
            SortOrder::Relevance => $type === 'playlist'
                ? $builder->orderByDesc('relevance')->orderByDesc('tracks_count')
                : $builder->orderByDesc('relevance')->orderByDesc('popularity'),
        };
    }
}
