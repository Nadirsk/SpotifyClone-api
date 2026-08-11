<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\ConcertQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConcertResource;
use App\Services\Events\ConcertService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, read-only. No source in `01`-`11` (see the `concerts` migration's
 * docblock) — built on explicit request as first-party, seeded data.
 */
final class ConcertController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ConcertService $concerts,
    ) {}

    /** GET /concerts */
    public function index(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            ConcertResource::collection($this->concerts->search(ConcertQuery::fromRequest($request))),
        );
    }

    /** GET /concerts/{id} */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(new ConcertResource($this->concerts->find($id)));
    }
}
