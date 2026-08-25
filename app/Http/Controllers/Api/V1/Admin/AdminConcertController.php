<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Concert\StoreConcertRequest;
use App\Http\Requests\Admin\Concert\UpdateConcertRequest;
use App\Http\Resources\ConcertResource;
use App\Services\Events\AdminConcertService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin management of concerts. Every route here sits behind
 * ['auth:sanctum', 'admin']. Reuses the public ConcertResource.
 */
final class AdminConcertController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminConcertService $concerts,
    ) {}

    /**
     * GET /api/v1/admin/concerts
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->concerts->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            ConcertResource::class,
        );
    }

    /**
     * POST /api/v1/admin/concerts
     */
    public function store(StoreConcertRequest $request): JsonResponse
    {
        $concert = $this->concerts->create($request->validated());

        return $this->respondCreated(new ConcertResource($concert), 'Concert created');
    }

    /**
     * GET /api/v1/admin/concerts/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(new ConcertResource($this->concerts->find($id)));
    }

    /**
     * PUT /api/v1/admin/concerts/{id}
     */
    public function update(UpdateConcertRequest $request, string $id): JsonResponse
    {
        $concert = $this->concerts->find($id);

        return $this->respondSuccess(
            new ConcertResource($this->concerts->update($concert, $request->validated())),
            'Concert updated',
        );
    }

    /**
     * DELETE /api/v1/admin/concerts/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->concerts->delete($this->concerts->find($id));

        return $this->respondNoContent();
    }
}
