<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VenueResource;
use App\Models\Venue;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Venues are immutable reference data with no filtering, sorting or
 * pagination — same reasoning as `GenreController` for skipping a service
 * and repository that would both be one-line pass-throughs.
 */
final class VenueController extends Controller
{
    use ApiResponse;

    /** GET /venues */
    public function index(): JsonResponse
    {
        return $this->respondSuccess(
            VenueResource::collection(Venue::query()->orderBy('name')->get()),
        );
    }
}
