<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Venue\StoreVenueRequest;
use App\Http\Requests\Admin\Venue\UpdateVenueRequest;
use App\Http\Resources\VenueResource;
use App\Services\Events\AdminVenueService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin management of venues. Every route here sits behind
 * ['auth:sanctum', 'admin']. Reuses the public VenueResource.
 */
final class AdminVenueController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminVenueService $venues,
    ) {}

    /**
     * GET /api/v1/admin/venues
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->venues->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            VenueResource::class,
        );
    }

    /**
     * POST /api/v1/admin/venues
     */
    public function store(StoreVenueRequest $request): JsonResponse
    {
        $venue = $this->venues->create($request->validated());

        return $this->respondCreated(new VenueResource($venue), 'Venue created');
    }

    /**
     * PUT /api/v1/admin/venues/{id}
     */
    public function update(UpdateVenueRequest $request, string $id): JsonResponse
    {
        $venue = $this->venues->find($id);

        return $this->respondSuccess(
            new VenueResource($this->venues->update($venue, $request->validated())),
            'Venue updated',
        );
    }

    /**
     * DELETE /api/v1/admin/venues/{id}
     *
     * Cascades to every concert at this venue — see AdminVenueService::delete().
     */
    public function destroy(string $id): JsonResponse
    {
        $this->venues->delete($this->venues->find($id));

        return $this->respondNoContent();
    }
}
