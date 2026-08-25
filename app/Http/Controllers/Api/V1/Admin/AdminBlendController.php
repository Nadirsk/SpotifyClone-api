<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlendResource;
use App\Services\Blend\AdminBlendService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Blend moderation for the admin panel: list, inspect and remove Blends.
 * There is no store/update here — a Blend is generated between real users
 * and its tracklist by an algorithm, never authored by an admin. Every
 * route sits behind ['auth:sanctum', 'admin'].
 */
final class AdminBlendController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminBlendService $blends,
    ) {}

    /**
     * GET /api/v1/admin/blends
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->blends->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            BlendResource::class,
        );
    }

    /**
     * GET /api/v1/admin/blends/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(new BlendResource($this->blends->find($id)));
    }

    /**
     * DELETE /api/v1/admin/blends/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->blends->delete($this->blends->find($id));

        return $this->respondNoContent();
    }
}
