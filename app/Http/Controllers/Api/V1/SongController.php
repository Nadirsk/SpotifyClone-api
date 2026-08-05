<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Services\Catalog\SongService;
use App\Services\Trending\TrendingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Song endpoints (05_API_SPECIFICATION §6).
 */
final class SongController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SongService $songs,
        private readonly TrendingService $trending,
    ) {}

    /**
     * GET /api/v1/songs
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respondPaginated(
            $this->songs->paginate(CatalogQuery::fromRequest($request)),
            SongResource::class,
        );
    }

    /**
     * GET /api/v1/songs/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(SongResource::make($this->songs->find($id)));
    }

    /**
     * GET /api/v1/songs/{id}/related
     */
    public function related(Request $request, string $id): JsonResponse
    {
        $limit = CatalogQuery::fromRequest($request)->limit;

        return $this->respondSuccess(
            SongResource::collection($this->songs->related($id, $limit)),
        );
    }

    /**
     * GET /api/v1/songs/{id}/preview
     *
     * Returns the provider's preview URL for the client to play directly. The
     * API never proxies or re-streams provider audio — see SongService::preview.
     */
    public function preview(string $id): JsonResponse
    {
        return $this->respondSuccess($this->songs->preview($id));
    }

    /**
     * GET /api/v1/songs/trending
     *
     * Must be registered before GET /songs/{id} or the wildcard swallows it.
     */
    public function trending(Request $request): JsonResponse
    {
        $limit = CatalogQuery::fromRequest($request)->limit;

        return $this->respondSuccess(
            SongResource::collection($this->trending->songs($limit)),
        );
    }
}
