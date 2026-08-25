<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Genre;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Write side of the genre taxonomy, for the admin panel. Genre has no
 * repository of its own — {@see TaxonomyService} reads it directly via
 * Eloquent — so this follows that same, simpler shape rather than
 * introducing a repository layer for one lean, admin-curated table.
 */
final class AdminGenreService
{
    /**
     * `GenreController::index()` caches the full list under this exact key
     * for an hour; every write here must drop it or a newly added genre
     * would not appear in the public dropdown until the TTL lapsed.
     */
    private const PUBLIC_LIST_CACHE_KEY = 'genres:all:v2';

    public function __construct(
        private readonly CacheService $cache,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Genre>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        $builder = Genre::query()->orderBy('name');

        if ($search !== null && $search !== '') {
            $builder->where('name', 'like', '%'.$search.'%');
        }

        /** @var LengthAwarePaginator<int, Genre> */
        return $builder->paginate(perPage: $limit, page: $page);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Genre
    {
        /** @var Genre */
        return Genre::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Genre
    {
        $genre = Genre::query()->create([
            ...$data,
            'slug' => $this->uniqueSlug((string) $data['name']),
        ]);

        $this->forgetPublicCache();

        return $genre;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Genre $genre, array $data): Genre
    {
        $genre->update($data);

        $this->forgetPublicCache();

        return $genre;
    }

    public function delete(Genre $genre): void
    {
        $genre->delete();

        $this->forgetPublicCache();
    }

    /**
     * `slug` carries a real unique index (unlike Song/Artist/Album, whose
     * slugs are cosmetic), so a collision has to be resolved rather than
     * left to surface as a raw 500 from the database. Only called on
     * create — like PlaylistService::update(), a rename deliberately
     * leaves the existing slug alone.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            throw ValidationException::withMessages([
                'name' => ['This name does not produce a usable slug.'],
            ]);
        }

        $slug = $base;
        $suffix = 2;

        while (Genre::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;

            if ($suffix > 50) {
                return $base.'-'.Str::lower(Str::random(6));
            }
        }

        return $slug;
    }

    private function forgetPublicCache(): void
    {
        $this->cache->forget('song', self::PUBLIC_LIST_CACHE_KEY);
    }
}
