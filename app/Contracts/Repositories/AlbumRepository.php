<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTO\CatalogQuery;
use App\Models\Album;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

interface AlbumRepository
{
    /** @return LengthAwarePaginator<int, Album> */
    public function paginate(CatalogQuery $query): LengthAwarePaginator;

    /** @throws ModelNotFoundException */
    public function findOrFail(string $id): Album;

    /** @return LengthAwarePaginator<int, Album> */
    public function forArtist(string $artistId, CatalogQuery $query): LengthAwarePaginator;

    /** @return Collection<int, Album> */
    public function trending(int $limit): Collection;

    /**
     * The admin panel's own listing: newest first, with an optional
     * title search. Never cached, unlike {@see paginate()} — an admin
     * editing an album needs to see the result immediately.
     *
     * @return LengthAwarePaginator<int, Album>
     */
    public function adminPaginate(int $page, int $limit, ?string $search): LengthAwarePaginator;

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Album;

    /** @param  array<string, mixed>  $data */
    public function update(Album $album, array $data): Album;

    public function delete(Album $album): void;
}
