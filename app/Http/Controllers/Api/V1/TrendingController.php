<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\ArtistResource;
use App\Http\Resources\SongResource;
use App\Services\Trending\TrendingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trending endpoints (05_API_SPECIFICATION §13).
 *
 * These are flat lists rather than paginated: the trending window is already
 * capped by config('music.trending.limit').
 */
final class TrendingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TrendingService $trending,
    ) {}

    /**
     * GET /api/v1/trending/songs
     *
     * `?window=today` asks for the daily chart — plays since local midnight,
     * counted rather than decayed. Anything else (including no parameter) keeps
     * the rolling 7-day trending list, so existing clients are unaffected.
     *
     * A `today` request can legitimately come back empty: below
     * `music.trending.min_today` plays there is not enough listening to rank.
     * The message says which window answered, so a caller can label the list
     * correctly instead of guessing — the "Top songs today" shelf showing a
     * weekly ranking was one of the reported bugs.
     */
    public function songs(Request $request): JsonResponse
    {
        $today = $request->query('window') === 'today';
        $songs = $today
            ? $this->trending->songsToday($this->limit($request))
            : $this->trending->songs($this->limit($request));

        return $this->respondSuccess(
            SongResource::collection($songs),
            $today ? 'Top songs today' : 'Trending songs',
        );
    }

    /**
     * GET /api/v1/trending/artists
     */
    public function artists(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            ArtistResource::collection($this->trending->artists($this->limit($request))),
        );
    }

    /**
     * GET /api/v1/trending/albums
     */
    public function albums(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            AlbumResource::collection($this->trending->albums($this->limit($request))),
        );
    }

    /**
     * Null lets TrendingService fall back to the configured default; it also
     * clamps whatever comes in here, so no bound is applied twice.
     */
    private function limit(Request $request): ?int
    {
        $limit = (int) $request->query('limit', '0');

        return $limit > 0 ? $limit : null;
    }
}
