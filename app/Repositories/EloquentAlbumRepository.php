<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\AlbumRepository;
use App\DTO\CatalogQuery;
use App\Models\Album;
use App\Repositories\Concerns\AppliesCatalogFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class EloquentAlbumRepository implements AlbumRepository
{
    use AppliesCatalogFilters;

    /**
     * Everything AlbumResource touches — see the note on
     * EloquentSongRepository::RELATIONS.
     *
     * @var list<string>
     */
    public const RELATIONS = ['artist', 'language'];

    public function paginate(CatalogQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Album> */
        return $this->applyCatalogQuery($this->baseQuery(), $query)
            ->paginate(perPage: $query->limit, page: $query->page);
    }

    public function findOrFail(string $id): Album
    {
        /** @var Album */
        return $this->baseQuery()->findOrFail($id);
    }

    public function forArtist(string $artistId, CatalogQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Album> */
        return $this->applyCatalogQuery($this->baseQuery(), $query)
            ->where('artist_id', $artistId)
            ->paginate(perPage: $query->limit, page: $query->page);
    }

    public function trending(int $limit): Collection
    {
        /** @var Collection<int, Album> */
        return $this->baseQuery()
            ->orderByDesc('trending_score')
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }

    protected function catalogEntity(): string
    {
        return self::ENTITY_ALBUM;
    }

    /** @return Builder<Album> */
    private function baseQuery(): Builder
    {
        return Album::query()->with(self::RELATIONS);
    }

    /*
     |--------------------------------------------------------------------------
     | Why album *listings* are not filtered to albums that hold tracks
     |--------------------------------------------------------------------------
     |
     | A third of the albums in this catalog carry no songs (they arrive named on
     | a song's credits or in an artist's discography, long before anything
     | fetches their track lists), and as a card in a shelf one of those is a
     | dead end — pressing play can only report that there is nothing to play.
     | `DatabaseSearchEngine` does filter them out of search results, and adding
     | `whereHas('songs')` here looked like the same fix for the same defect.
     |
     | It is not affordable on this shape of query. Search applies the filter to
     | a candidate set a FULLTEXT match has already reduced to a handful of rows
     | (measured: 0.3s). A listing has no such bound, so the paginator's own
     | COUNT(*) runs the semi-join across all 34k albums, joining on a 36-char
     | UUID foreign key: measured at 8.7s for the count alone, and 62s end to end
     | for `GET /albums?limit=20` — with `artisan serve` handling one request at a
     | time, that took the whole API down with it.
     |
     | Doing this properly means a maintained counter column on `albums` (the
     | existing `total_tracks` is the provider's claim, 0 for ~92% of rows, and
     | cannot stand in for it) written by the sync path and backfilled once. That
     | is a schema change with its own migration and its own tests, so it is left
     | as the deliberate next step rather than smuggled in behind a one-line
     | `whereHas`.
     */
}
