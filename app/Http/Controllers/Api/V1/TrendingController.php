<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\SongResource;
use App\Services\Trending\TrendingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trending endpoints (05_API_SPECIFICATION §13).
 *
 * These are flat lists rather than paginated: the trending window is already
 * capped by config('music.trending.limit').
 */
final class TrendingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TrendingService $trending,
    ) {}

    /**
     * GET /api/v1/trending/songs
     */
    public function songs(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            SongResource::collection($this->trending->songs($this->limit($request))),
        );
    }

    /**
     * GET /api/v1/trending/artists
     */
    public function artists(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            ArtistResource::collection($this->trending->artists($this->limit($request))),
        );
    }

    /**
     * GET /api/v1/trending/albums
     */
    public function albums(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            AlbumResource::collection($this->trending->albums($this->limit($request))),
        );
    }

    /**
     * Null lets TrendingService fall back to the configured default; it also
     * clamps whatever comes in here, so no bound is applied twice.
     */
    private function limit(Request $request): ?int
    {
        $limit = (int) $request->query('limit', '0');

        return $limit > 0 ? $limit : null;
    }
}
