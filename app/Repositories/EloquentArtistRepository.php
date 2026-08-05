<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ArtistRepository;
use App\DTO\CatalogQuery;
use App\Models\Artist;
use App\Models\Song;
use App\Repositories\Concerns\AppliesCatalogFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class EloquentArtistRepository implements ArtistRepository
{
    use AppliesCatalogFilters;

    public function paginate(CatalogQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Artist> */
        return $this->applyCatalogQuery(Artist::query(), $query)
            ->paginate(perPage: $query->limit, page: $query->page);
    }

    public function findOrFail(string $id): Artist
    {
        /** @var Artist */
        return Artist::query()->findOrFail($id);
    }

    public function related(Artist $artist, int $limit): Collection
    {
        /*
         | Genre lives on songs, not artists, so "shares a genre" is expressed as
         | a correlated EXISTS over the other artist's songs. The genre id list
         | stays a subquery rather than being pulled into PHP.
         */
        $genreIds = Song::query()
            ->select('genre_id')
            ->where('artist_id', $artist->getKey())
            ->whereNotNull('genre_id');

        /** @var Collection<int, Artist> */
        return Artist::query()
            ->whereKeyNot($artist->getKey())
            ->whereHas(
                'songs',
                fn (Builder $q): Builder => $q->whereIn('genre_id', $genreIds)
            )
            ->orderByDesc('popularity')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function trending(int $limit): Collection
    {
        /** @var Collection<int, Artist> */
        return Artist::query()
            ->orderByDesc('trending_score')
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }

    public function popularSongs(Artist $artist, int $limit): Collection
    {
        /** @var Collection<int, Song> */
        return Song::query()
            ->with(EloquentSongRepository::RELATIONS)
            ->where('artist_id', $artist->getKey())
            ->orderByDesc('popularity')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    protected function catalogEntity(): string
    {
        return self::ENTITY_ARTIST;
    }
}
