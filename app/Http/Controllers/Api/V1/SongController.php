<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Enums\AudioQuality;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\User;
use App\Services\Catalog\PlaybackService;
use App\Services\Catalog\SongService;
use App\Services\Trending\TrendingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Song endpoints (05_API_SPECIFICATION §6).
 */
final class SongController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SongService $songs,
        private readonly TrendingService $trending,
        private readonly PlaybackService $playback,
    ) {}

    /**
     * GET /api/v1/songs
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respondPaginated(
            $this->songs->paginate(CatalogQuery::fromRequest($request)),
            SongResource::class,
        );
    }

    /**
     * GET /api/v1/songs/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(SongResource::make($this->songs->find($id)));
    }

    /**
     * GET /api/v1/songs/{id}/related
     */
    public function related(Request $request, string $id): JsonResponse
    {
        $limit = CatalogQuery::fromRequest($request)->limit;

        return $this->respondSuccess(
            SongResource::collection($this->songs->related($id, $limit)),
        );
    }

    /**
     * GET /api/v1/songs/{id}/preview
     *
     * Returns the provider's preview URL for the client to play directly. The
     * API never proxies or re-streams provider audio — see SongService::preview.
     */
    public function preview(string $id): JsonResponse
    {
        return $this->respondSuccess($this->songs->preview($id));
    }

    /**
     * GET /api/v1/songs/{id}/stream
     *
     * The quality-aware successor to `preview` above. Unauthenticated callers
     * are served the free tier's ceiling rather than refused, so a guest can
     * still listen; `quality` in the response is what was actually resolved,
     * which is not always what `?quality=` asked for.
     */
    public function stream(Request $request, string $id): JsonResponse
    {
        return $this->respondSuccess(
            $this->playback->stream($id, $request->user(), $this->requestedQuality($request)),
            'Stream resolved',
        );
    }

    /**
     * GET /api/v1/songs/{id}/download
     *
     * Premium only, and the one endpoint that puts provider bytes through this
     * server — see `PlaybackService::download()` for what that costs. Returns
     * the audio itself, not a URL, so the browser can save it under a real
     * filename and a Service Worker can hold it for offline playback.
     */
    public function download(Request $request, string $id): StreamedResponse
    {
        return $this->playback->download($id, $this->user($request), $this->requestedQuality($request));
    }

    /**
     * GET /api/v1/songs/trending
     *
     * Must be registered before GET /songs/{id} or the wildcard swallows it.
     */
    public function trending(Request $request): JsonResponse
    {
        $limit = CatalogQuery::fromRequest($request)->limit;

        return $this->respondSuccess(
            SongResource::collection($this->trending->songs($limit)),
        );
    }

    /**
     * `?quality=` if it names a real tier, null otherwise.
     *
     * An unrecognised value is ignored rather than 422'd: the fallback is the
     * listener's own saved preference, which is a better answer than an error
     * for a query parameter a stale client might still be sending.
     */
    private function requestedQuality(Request $request): ?AudioQuality
    {
        return AudioQuality::tryFrom((string) $request->query('quality', ''));
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
