<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\SongRepository;
use App\DTO\CatalogQuery;
use App\Models\Song;
use App\Repositories\Concerns\AppliesCatalogFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class EloquentSongRepository implements SongRepository
{
    use AppliesCatalogFilters;

    /**
     * Everything SongResource touches. Lazy loading is disabled outside
     * production, so an omission here is a 500, not a slow query.
     *
     * @var list<string>
     */
    public const RELATIONS = ['artist', 'album', 'genre', 'language'];

    public function paginate(CatalogQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Song> */
        return $this->applyCatalogQuery($this->baseQuery(), $query)
            ->paginate(perPage: $query->limit, page: $query->page);
    }

    public function findOrFail(string $id): Song
    {
        /** @var Song */
        return $this->baseQuery()->findOrFail($id);
    }

    public function related(Song $song, int $limit): Collection
    {
        if ($song->genre_id !== null || $song->language_id !== null) {
            $byTaxonomy = $this->relatedByTaxonomy($song, $limit);

            if ($byTaxonomy->isNotEmpty()) {
                return $byTaxonomy;
            }
        }

        /*
         | A song with no genre/language, or the only one in its niche, would
         | otherwise return nothing. The same artist is the next best guess.
         */
        return $this->relatedByArtist($song, $limit);
    }

    public function trending(int $limit): Collection
    {
        /** @var Collection<int, Song> */
        return $this->baseQuery()
            ->orderByDesc('trending_score')
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }

    public function forAlbum(string $albumId): Collection
    {
        /*
         | track_number is the album's own running order. It is nullable because
         | some providers omit it, so those rows sort last on insertion order
         | rather than jumping to the front the way NULL would in MySQL.
         */
        /** @var Collection<int, Song> */
        return $this->baseQuery()
            ->where('album_id', $albumId)
            ->orderByRaw('track_number IS NULL')
            ->orderBy('track_number')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function forArtist(string $artistId, CatalogQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Song> */
        return $this->applyCatalogQuery($this->baseQuery(), $query)
            ->where('artist_id', $artistId)
            ->paginate(perPage: $query->limit, page: $query->page);
    }

    public function incrementPlayCount(Song $song): void
    {
        $song->increment('play_count');
    }

    protected function catalogEntity(): string
    {
        return self::ENTITY_SONG;
    }

    /** @return Builder<Song> */
    private function baseQuery(): Builder
    {
        return Song::query()->with(self::RELATIONS);
    }

    /** @return Collection<int, Song> */
    private function relatedByTaxonomy(Song $song, int $limit): Collection
    {
        /** @var Collection<int, Song> */
        return $this->baseQuery()
            ->whereKeyNot($song->getKey())
            ->when(
                $song->genre_id !== null,
                fn (Builder $q): Builder => $q->where('genre_id', $song->genre_id)
            )
            ->when(
                $song->language_id !== null,
                fn (Builder $q): Builder => $q->where('language_id', $song->language_id)
            )
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Song> */
    private function relatedByArtist(Song $song, int $limit): Collection
    {
        /** @var Collection<int, Song> */
        return $this->baseQuery()
            ->where('artist_id', $song->artist_id)
            ->whereKeyNot($song->getKey())
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }
}
