<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenreResource;
use App\Models\Genre;
use App\Services\Cache\CacheService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Genre reference list, used to populate the filter dropdown in
 * 05_API_SPECIFICATION §16.
 *
 * Genres are immutable reference data with no filtering, sorting or pagination,
 * so this reads the model directly instead of carrying a service and repository
 * that would both be one-line pass-throughs. If genre ever grows behaviour, it
 * gets the full stack like every other entity.
 */
final class GenreController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CacheService $cache,
    ) {}

    /**
     * GET /api/v1/genres
     */
    public function index(): JsonResponse
    {
        // No dedicated reference-data TTL exists; genres are a song facet and
        // change no more often than the song catalog does.
        $genres = $this->cache->remember(
            'song',
            'genres:all',
            static fn (): Collection => Genre::query()->orderBy('name')->get(),
        );

        return $this->respondSuccess(GenreResource::collection($genres));
    }
}
