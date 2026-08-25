<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Album\StoreAlbumRequest;
use App\Http\Requests\Admin\Album\UpdateAlbumRequest;
use App\Http\Resources\Admin\AdminAlbumResource;
use App\Services\Catalog\AdminAlbumService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin catalog management for albums. Every route here sits behind
 * ['auth:sanctum', 'admin'].
 */
final class AdminAlbumController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminAlbumService $albums,
    ) {}

    /**
     * GET /api/v1/admin/albums
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->albums->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            AdminAlbumResource::class,
        );
    }

    /**
     * POST /api/v1/admin/albums
     */
    public function store(StoreAlbumRequest $request): JsonResponse
    {
        $album = $this->albums->create($request->validated());

        return $this->respondCreated(new AdminAlbumResource($album), 'Album created');
    }

    /**
     * GET /api/v1/admin/albums/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(new AdminAlbumResource($this->albums->find($id)));
    }

    /**
     * PUT /api/v1/admin/albums/{id}
     */
    public function update(UpdateAlbumRequest $request, string $id): JsonResponse
    {
        $album = $this->albums->find($id);

        return $this->respondSuccess(
            new AdminAlbumResource($this->albums->update($album, $request->validated())),
            'Album updated',
        );
    }

    /**
     * DELETE /api/v1/admin/albums/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->albums->delete($this->albums->find($id));

        return $this->respondNoContent();
    }
}
