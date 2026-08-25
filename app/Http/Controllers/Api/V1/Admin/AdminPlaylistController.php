<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Services\Library\AdminPlaylistService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Playlist moderation for the admin panel: list, inspect and remove
 * user-created playlists. There is no store/update here — creating or
 * editing a playlist is the owner's action, never the admin's, on their
 * behalf. Every route sits behind ['auth:sanctum', 'admin'].
 */
final class AdminPlaylistController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminPlaylistService $playlists,
    ) {}

    /**
     * GET /api/v1/admin/playlists
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->playlists->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            PlaylistResource::class,
        );
    }

    /**
     * GET /api/v1/admin/playlists/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(new PlaylistResource($this->playlists->find($id)));
    }

    /**
     * DELETE /api/v1/admin/playlists/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->playlists->delete($this->playlists->find($id));

        return $this->respondNoContent();
    }
}
