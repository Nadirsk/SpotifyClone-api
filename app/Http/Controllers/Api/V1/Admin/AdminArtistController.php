<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Artist\StoreArtistRequest;
use App\Http\Requests\Admin\Artist\UpdateArtistRequest;
use App\Http\Resources\Admin\AdminArtistResource;
use App\Services\Catalog\AdminArtistService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin catalog management for artists — the write side ArtistController
 * does not expose. Every route here sits behind ['auth:sanctum', 'admin'].
 */
final class AdminArtistController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminArtistService $artists,
    ) {}

    /**
     * GET /api/v1/admin/artists
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->artists->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            AdminArtistResource::class,
        );
    }

    /**
     * POST /api/v1/admin/artists
     */
    public function store(StoreArtistRequest $request): JsonResponse
    {
        $artist = $this->artists->create($request->validated());

        return $this->respondCreated(new AdminArtistResource($artist), 'Artist created');
    }

    /**
     * GET /api/v1/admin/artists/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(new AdminArtistResource($this->artists->find($id)));
    }

    /**
     * PUT /api/v1/admin/artists/{id}
     */
    public function update(UpdateArtistRequest $request, string $id): JsonResponse
    {
        $artist = $this->artists->find($id);

        return $this->respondSuccess(
            new AdminArtistResource($this->artists->update($artist, $request->validated())),
            'Artist updated',
        );
    }

    /**
     * DELETE /api/v1/admin/artists/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->artists->delete($this->artists->find($id));

        return $this->respondNoContent();
    }
}
