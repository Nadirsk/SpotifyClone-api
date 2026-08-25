<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Language;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Write side of the language taxonomy, for the admin panel. Language has
 * no repository of its own — {@see TaxonomyService} reads it directly via
 * Eloquent, same as Genre — so this follows that same simpler shape.
 */
final class AdminLanguageService
{
    /**
     * `LanguageController::index()` caches the full list under this exact
     * key for an hour; every write here must drop it — same reasoning as
     * {@see AdminGenreService}.
     */
    private const PUBLIC_LIST_CACHE_KEY = 'languages:all:v2';

    public function __construct(
        private readonly CacheService $cache,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Language>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        $builder = Language::query()->orderBy('name');

        if ($search !== null && $search !== '') {
            $builder->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%');
            });
        }

        /** @var LengthAwarePaginator<int, Language> */
        return $builder->paginate(perPage: $limit, page: $page);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Language
    {
        /** @var Language */
        return Language::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Language
    {
        $language = Language::query()->create($data);

        $this->forgetPublicCache();

        return $language;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Language $language, array $data): Language
    {
        $language->update($data);

        $this->forgetPublicCache();

        return $language;
    }

    public function delete(Language $language): void
    {
        $language->delete();

        $this->forgetPublicCache();
    }

    private function forgetPublicCache(): void
    {
        $this->cache->forget('song', self::PUBLIC_LIST_CACHE_KEY);
    }
}
