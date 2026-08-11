<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Services\Recommendations\RecommendationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personalized recommendations, derived from the caller's own favourites —
 * see `RecommendationService`'s docblock for why this is one endpoint, not
 * the three-endpoint (`/recommendations`, `/recommendations/artists`,
 * `/recommendations/albums`) shape some references describe.
 */
final class RecommendationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RecommendationService $recommendations,
    ) {}

    /**
     * GET /api/v1/recommendations
     */
    public function songs(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', '0');

        return $this->respondSuccess(
            SongResource::collection(
                $this->recommendations->songsFor($request->user(), $limit > 0 ? $limit : null),
            ),
        );
    }
}
