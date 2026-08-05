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
}
