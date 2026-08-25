<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTO\CatalogQuery;
use App\Models\Artist;
use App\Models\Song;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

interface ArtistRepository
{
    /** @return LengthAwarePaginator<int, Artist> */
    public function paginate(CatalogQuery $query): LengthAwarePaginator;

    /** @throws ModelNotFoundException */
    public function findOrFail(string $id): Artist;

    /**
     * Artists sharing genres with $artist, most popular first.
     *
     * @return Collection<int, Artist>
     */
    public function related(Artist $artist, int $limit): Collection;

    /** @return Collection<int, Artist> */
    public function trending(int $limit): Collection;

    /**
     * The artist's most popular songs, for the artist page.
     *
     * @return Collection<int, Song>
     */
    public function popularSongs(Artist $artist, int $limit): Collection;

    /**
     * The admin panel's own listing: newest first, with an optional
     * name search. Never cached, unlike {@see paginate()} — an admin
     * editing an artist needs to see the result immediately.
     *
     * @return LengthAwarePaginator<int, Artist>
     */
    public function adminPaginate(int $page, int $limit, ?string $search): LengthAwarePaginator;

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Artist;

    /** @param  array<string, mixed>  $data */
    public function update(Artist $artist, array $data): Artist;

    public function delete(Artist $artist): void;
}
