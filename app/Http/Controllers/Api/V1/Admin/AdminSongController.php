<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Song\StoreSongRequest;
use App\Http\Requests\Admin\Song\UpdateSongRequest;
use App\Http\Resources\Admin\AdminSongResource;
use App\Services\Catalog\AdminSongService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin catalog management for songs — the write side SongController does not
 * expose. Every route here sits behind ['auth:sanctum', 'admin'].
 */
final class AdminSongController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminSongService $songs,
    ) {}

    /**
     * GET /api/v1/admin/songs
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->songs->paginate(
                CatalogQuery::fromRequest($request),
                is_string($search) ? $search : null,
            ),
            AdminSongResource::class,
        );
    }

    /**
     * POST /api/v1/admin/songs
     */
    public function store(StoreSongRequest $request): JsonResponse
    {
        $song = $this->songs->create($request->validated());

        return $this->respondCreated(new AdminSongResource($song), 'Song created');
    }

    /**
     * GET /api/v1/admin/songs/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(new AdminSongResource($this->songs->find($id)));
    }

    /**
     * PUT /api/v1/admin/songs/{id}
     */
    public function update(UpdateSongRequest $request, string $id): JsonResponse
    {
        $song = $this->songs->find($id);

        return $this->respondSuccess(
            new AdminSongResource($this->songs->update($song, $request->validated())),
            'Song updated',
        );
    }

    /**
     * DELETE /api/v1/admin/songs/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->songs->delete($this->songs->find($id));

        return $this->respondNoContent();
    }
}
