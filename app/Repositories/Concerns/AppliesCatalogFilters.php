<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use App\DTO\CatalogQuery;
use App\Enums\SortOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Translates a CatalogQuery into `where` clauses and ordering.
 *
 * Songs, artists and albums accept the same query string but not the same
 * columns, so the using repository declares which entity it is filtering and
 * the trait skips whatever does not apply.
 */
trait AppliesCatalogFilters
{
    protected const ENTITY_SONG = 'song';

    protected const ENTITY_ARTIST = 'artist';

    protected const ENTITY_ALBUM = 'album';

    /** One of the ENTITY_* constants above. */
    abstract protected function catalogEntity(): string;

    /**
     * @param  Builder<Model>  $builder
     * @return Builder<Model>
     */
    protected function applyCatalogQuery(Builder $builder, CatalogQuery $query): Builder
    {
        return $this->applyCatalogSort($this->applyCatalogFilters($builder, $query), $query);
    }

    /**
     * @param  Builder<Model>  $builder
     * @return Builder<Model>
     */
    protected function applyCatalogFilters(Builder $builder, CatalogQuery $query): Builder
    {
        $entity = $this->catalogEntity();

        $genre = $query->filter('genre');
        if ($genre !== null) {
            /*
             | Only songs carry a genre. An artist or album "in" a genre is one
             | with at least one song in it, which is what a listener means by
             | /artists?genre=rock.
             */
            $relation = $entity === self::ENTITY_SONG ? 'genre' : 'songs.genre';

            $builder->whereHas(
                $relation,
                fn (Builder $q) => $q->where('slug', $genre)->orWhere('name', $genre)
            );
        }

        $language = $query->filter('language');
        if ($language !== null) {
            $relation = $entity === self::ENTITY_ARTIST ? 'songs.language' : 'language';

            $builder->whereHas(
                $relation,
                fn (Builder $q) => $q->where('code', $language)->orWhere('name', $language)
            );
        }

        $country = $query->filter('country');
        if ($country !== null) {
            $entity === self::ENTITY_ARTIST
                ? $builder->where('country', $country)
                : $builder->whereHas('artist', fn (Builder $q) => $q->where('country', $country));
        }

        $releaseYear = $query->filter('release_year');
        if ($releaseYear !== null && $this->catalogHasReleaseDate()) {
            $builder->whereYear('release_date', (int) $releaseYear);
        }

        $minPopularity = $query->filter('min_popularity');
        if ($minPopularity !== null) {
            $builder->where('popularity', '>=', (int) $minPopularity);
        }

        if ($entity === self::ENTITY_SONG) {
            $minDuration = $query->filter('min_duration');
            if ($minDuration !== null) {
                $builder->where('duration', '>=', (int) $minDuration);
            }

            $maxDuration = $query->filter('max_duration');
            if ($maxDuration !== null) {
                $builder->where('duration', '<=', (int) $maxDuration);
            }

            $albumId = $query->filter('album_id');
            if ($albumId !== null) {
                $builder->where('album_id', $albumId);
            }
        }

        $artistId = $query->filter('artist_id');
        // An artist listing filtered by artist_id is a lookup, not a listing — ignored.
        if ($artistId !== null && $entity !== self::ENTITY_ARTIST) {
            $builder->where('artist_id', $artistId);
        }

        return $builder;
    }

    /**
     * @param  Builder<Model>  $builder
     * @return Builder<Model>
     */
    protected function applyCatalogSort(Builder $builder, CatalogQuery $query): Builder
    {
        $hasReleaseDate = $this->catalogHasReleaseDate();

        $sorted = match ($query->sort) {
            SortOrder::Newest => $hasReleaseDate
                /*
                 | Unreleased rows are excluded, not merely ranked. Provider
                 | metadata that supplies only a year becomes January 1st of that
                 | year, and a few rows carry a year that has not happened yet —
                 | 2027, 2028. Ordered by `release_date DESC` those sat at the
                 | head of the home page's "New releases" shelf, ahead of records
                 | genuinely released this week, which is what that shelf was
                 | reported as getting wrong.
                 |
                 | Excluding rather than clamping is the honest reading: whatever
                 | the bad date means, an album the catalog believes is not out
                 | yet is not a new release.
                 */
                ? $builder->whereDate('release_date', '<=', Carbon::today())->orderByDesc('release_date')
                : $builder->orderByDesc('created_at'),
            SortOrder::Oldest => $hasReleaseDate
                ? $builder->orderBy('release_date')
                : $builder->orderBy('created_at'),
            SortOrder::Alphabetical => $builder->orderBy($this->catalogTitleColumn()),
            /*
             | A catalog listing has no search term, so there is no relevance
             | score to sort on. Popularity is the closest honest answer.
             */
            SortOrder::Popularity, SortOrder::Relevance => $builder->orderByDesc('popularity'),
        };

        // Ties are common (popularity is a 0-100 integer); without a unique
        // tiebreaker MySQL may repeat or drop rows across pages.
        return $sorted->orderBy('id');
    }

    private function catalogHasReleaseDate(): bool
    {
        return $this->catalogEntity() !== self::ENTITY_ARTIST;
    }

    private function catalogTitleColumn(): string
    {
        return $this->catalogEntity() === self::ENTITY_ARTIST ? 'name' : 'title';
    }
}
