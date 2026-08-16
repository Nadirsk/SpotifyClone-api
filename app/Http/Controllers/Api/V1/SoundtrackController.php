<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Services\Catalog\SoundtrackService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Soundtracks browse hub — film and show music, grouped by title.
 *
 * No source in 01–11; built on explicit request as a browse surface over the
 * `film_title` column `SoundtrackParser` derives. Public, like the rest of the
 * catalog.
 *
 * A film is keyed by its own name (see `SoundtrackService`'s docblock), so the
 * `{film}` segment is URL-encoded free text rather than an id.
 */
final class SoundtrackController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SoundtrackService $soundtracks,
    ) {}

    /** GET /api/v1/soundtracks */
    public function index(Request $request): JsonResponse
    {
        $query = CatalogQuery::fromRequest($request);

        return $this->respondPaginated(
            $this->soundtracks->films($query->page, $query->limit),
            message: 'Soundtracks retrieved',
        );
    }

    /** GET /api/v1/soundtracks/{film} */
    public function show(Request $request, string $film): JsonResponse
    {
        $limit = CatalogQuery::fromRequest($request)->limit;
        $result = $this->soundtracks->film(urldecode($film), $limit);

        return $this->respondSuccess([
            ...$result,
            'songs' => SongResource::collection($result['songs'])->resolve(),
        ], 'Soundtrack retrieved');
    }
}
