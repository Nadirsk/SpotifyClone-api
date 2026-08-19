<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenreResource;
use App\Services\Cache\CacheService;
use App\Services\Catalog\TaxonomyService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Genre reference list, used to populate the filter dropdown in
 * 05_API_SPECIFICATION §16 and the Browse grid.
 *
 * This used to read the model directly, on the grounds that a genre was a bare
 * name with no behaviour worth a service. It has behaviour now: each row also
 * reports how many songs are in it and one real cover to show for it, which is
 * what lets a client tell a genre with a catalog behind it from an empty one.
 * That derivation lives in TaxonomyService, per this file's own former note
 * that genre gets the full stack the moment it stops being a pass-through.
 */
final class GenreController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CacheService $cache,
        private readonly TaxonomyService $taxonomy,
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
            'genres:all:v2',
            fn (): Collection => $this->taxonomy->genres(),
        );

        return $this->respondSuccess(GenreResource::collection($genres));
    }
}
