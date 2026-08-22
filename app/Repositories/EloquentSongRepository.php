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

    /**
     * What a single song's own endpoint loads on top of {@see RELATIONS}.
     *
     * Credits are deliberately not in RELATIONS. A tracklist or a search page
     * serialises up to fifty songs and none of them shows a credits block, so
     * loading four or five credit rows and their artists per song would be
     * hundreds of rows fetched to be thrown away. `GET /songs/{id}` is the one
     * place a full credits list is asked for, and it is one song.
     *
     * @var list<string>
     */
    public const DETAIL_RELATIONS = ['credits.artist'];

    public function paginate(CatalogQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Song> */
        return $this->applyCatalogQuery($this->baseQuery(), $query)
            ->paginate(perPage: $query->limit, page: $query->page);
    }

    public function findOrFail(string $id): Song
    {
        /** @var Song */
        return $this->baseQuery()->with(self::DETAIL_RELATIONS)->findOrFail($id);
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

    public function findMany(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, Song> */
        return $this->baseQuery()->whereIn('id', $ids)->get();
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

    /**
     * Everything an artist is credited on, not only what they are labelled with.
     *
     * This used to be `where('artist_id', $artistId)` — the display artist and
     * nothing else. The result was a discography that was accurate and badly
     * incomplete: a music director's page listed the handful of tracks where the
     * sync adapter happened to elect them the display name and omitted the rest
     * of what they had written, and a featured vocalist's page omitted every
     * guest appearance. Neither could be expressed, because a song row has room
     * for exactly one artist.
     *
     * {@see Song::scopeCreditedTo()} holds the definition, shared with the
     * artist page's Popular shelf so the two lists cannot disagree about what an
     * artist's songs are.
     */
    public function forArtist(string $artistId, CatalogQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Song> */
        return $this->applyCatalogQuery($this->baseQuery(), $query)
            ->creditedTo($artistId)
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
