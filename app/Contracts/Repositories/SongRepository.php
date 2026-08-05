<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\DTO\CatalogQuery;
use App\Models\Song;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

interface SongRepository
{
    /** @return LengthAwarePaginator<int, Song> */
    public function paginate(CatalogQuery $query): LengthAwarePaginator;

    /** @throws ModelNotFoundException */
    public function findOrFail(string $id): Song;

    /**
     * Songs a listener of $song is likely to want next: same genre and
     * language, most popular first, excluding $song itself.
     *
     * @return Collection<int, Song>
     */
    public function related(Song $song, int $limit): Collection;

    /** @return Collection<int, Song> */
    public function trending(int $limit): Collection;

    /** @return Collection<int, Song> */
    public function forAlbum(string $albumId): Collection;

    /**
     * @return LengthAwarePaginator<int, Song>
     */
    public function forArtist(string $artistId, CatalogQuery $query): LengthAwarePaginator;

    public function incrementPlayCount(Song $song): void;
}
