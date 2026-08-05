<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\SearchQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\PlaylistResource;
use App\Http\Resources\SongResource;
use App\Services\Search\SearchService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Search endpoints (05_API_SPECIFICATION §5, 06_SEARCH_ARCHITECTURE §12–13).
 */
final class SearchController extends Controller
{
    use ApiResponse;

    /**
     * Serialiser per searchable type. Keys mirror config('search.types'), which
     * is also what SearchRequest validates `type` against.
     *
     * @var array<string, class-string<JsonResource>>
     */
    private const RESOURCES = [
        'song' => SongResource::class,
        'artist' => ArtistResource::class,
        'album' => AlbumResource::class,
        'playlist' => PlaylistResource::class,
    ];

    public function __construct(
        private readonly SearchService $search,
    ) {}

    /**
     * GET /api/v1/search
     *
     * With `type` the response is a paginated list of that one type; without it
     * the response is the grouped shape from 06_SEARCH_ARCHITECTURE §13.
     */
    public function index(SearchRequest $request): JsonResponse
    {
        $query = SearchQuery::fromRequest($request);
        $user = $request->user();

        if ($query->type !== null) {
            return $this->respondPaginated(
                $this->search->searchType($query, $user),
                self::RESOURCES[$query->type],
            );
        }

        $results = $this->search->searchAll($query, $user);

        return $this->respondSuccess([
            'songs' => SongResource::collection($results->songs)->resolve(),
            'artists' => ArtistResource::collection($results->artists)->resolve(),
            'albums' => AlbumResource::collection($results->albums)->resolve(),
            'playlists' => PlaylistResource::collection($results->playlists)->resolve(),
        ]);
    }

    /**
     * GET /api/v1/search/suggest
     *
     * Must be registered before any /search/{...} wildcard.
     */
    public function suggest(SearchRequest $request): JsonResponse
    {
        $limit = $request->integer('limit') > 0 ? $request->integer('limit') : null;

        return $this->respondSuccess([
            'suggestions' => $this->search->suggest((string) $request->query('q'), $limit)->all(),
        ]);
    }
}
