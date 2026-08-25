<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Genre\StoreGenreRequest;
use App\Http\Requests\Admin\Genre\UpdateGenreRequest;
use App\Http\Resources\GenreResource;
use App\Services\Catalog\AdminGenreService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin management of the genre taxonomy. Every route here sits behind
 * ['auth:sanctum', 'admin']. Reuses the public GenreResource: its fields
 * (id, name, slug) are exactly what the admin screen shows too.
 */
final class AdminGenreController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminGenreService $genres,
    ) {}

    /**
     * GET /api/v1/admin/genres
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->genres->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            GenreResource::class,
        );
    }

    /**
     * POST /api/v1/admin/genres
     */
    public function store(StoreGenreRequest $request): JsonResponse
    {
        $genre = $this->genres->create($request->validated());

        return $this->respondCreated(new GenreResource($genre), 'Genre created');
    }

    /**
     * PUT /api/v1/admin/genres/{id}
     */
    public function update(UpdateGenreRequest $request, string $id): JsonResponse
    {
        $genre = $this->genres->find($id);

        return $this->respondSuccess(
            new GenreResource($this->genres->update($genre, $request->validated())),
            'Genre updated',
        );
    }

    /**
     * DELETE /api/v1/admin/genres/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->genres->delete($this->genres->find($id));

        return $this->respondNoContent();
    }
}
