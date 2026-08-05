<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\SongResource;
use App\Services\Catalog\AlbumService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Album endpoints (05_API_SPECIFICATION §8).
 */
final class AlbumController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AlbumService $albums,
    ) {}

    /**
     * GET /api/v1/albums
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respondPaginated(
            $this->albums->paginate(CatalogQuery::fromRequest($request)),
            AlbumResource::class,
        );
    }

    /**
     * GET /api/v1/albums/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(AlbumResource::make($this->albums->find($id)));
    }

    /**
     * GET /api/v1/albums/{id}/tracks
     *
     * Unpaginated: an album's track list is bounded by the album itself.
     */
    public function tracks(string $id): JsonResponse
    {
        return $this->respondSuccess(
            SongResource::collection($this->albums->tracks($id)),
        );
    }
}
