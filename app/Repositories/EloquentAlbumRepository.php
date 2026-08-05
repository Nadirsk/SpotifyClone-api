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
}
