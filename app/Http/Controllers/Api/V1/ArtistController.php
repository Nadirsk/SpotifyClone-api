<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\SongResource;
use App\Services\Catalog\ArtistService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Artist endpoints (05_API_SPECIFICATION §7).
 */
final class ArtistController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ArtistService $artists,
    ) {}

    /**
     * GET /api/v1/artists
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respondPaginated(
            $this->artists->paginate(CatalogQuery::fromRequest($request)),
            ArtistResource::class,
        );
    }

    /**
     * GET /api/v1/artists/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(ArtistResource::make($this->artists->find($id)));
    }

    /**
     * GET /api/v1/artists/{id}/albums
     */
    public function albums(Request $request, string $id): JsonResponse
    {
        return $this->respondPaginated(
            $this->artists->albums($id, CatalogQuery::fromRequest($request)),
            AlbumResource::class,
        );
    }

    /**
     * GET /api/v1/artists/{id}/songs
     */
    public function songs(Request $request, string $id): JsonResponse
    {
        return $this->respondPaginated(
            $this->artists->songs($id, CatalogQuery::fromRequest($request)),
            SongResource::class,
        );
    }

    /**
     * GET /api/v1/artists/{id}/related
     */
    public function related(Request $request, string $id): JsonResponse
    {
        $limit = CatalogQuery::fromRequest($request)->limit;

        return $this->respondSuccess(
            ArtistResource::collection($this->artists->related($id, $limit)),
        );
    }
}
