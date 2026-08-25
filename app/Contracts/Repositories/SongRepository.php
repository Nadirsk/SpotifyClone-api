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

    /**
     * Many songs by id, with catalog relations loaded, in no particular
     * order — the caller (BlendGenerationService) re-sorts by its own score.
     *
     * @param  list<string>  $ids
     * @return Collection<int, Song>
     */
    public function findMany(array $ids): Collection;

    /** @return Collection<int, Song> */
    public function trending(int $limit): Collection;

    /** @return Collection<int, Song> */
    public function forAlbum(string $albumId): Collection;

    /**
     * @return LengthAwarePaginator<int, Song>
     */
    public function forArtist(string $artistId, CatalogQuery $query): LengthAwarePaginator;

    public function incrementPlayCount(Song $song): void;

    /**
     * The admin panel's own listing: same filters/sort as {@see paginate()}
     * plus a free-text title search, and never cached — an admin editing a
     * song needs to see the result immediately, not after the `song` cache
     * bucket's hour-long TTL.
     *
     * @return LengthAwarePaginator<int, Song>
     */
    public function adminPaginate(CatalogQuery $query, ?string $search): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Song;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Song $song, array $data): Song;

    public function delete(Song $song): void;
}
