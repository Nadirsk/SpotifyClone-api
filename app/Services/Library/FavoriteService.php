<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\FavoriteRepository;
use App\Contracts\Repositories\SongRepository;
use App\Models\Song;
use App\Models\User;
use App\Services\Cache\CacheService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Favourites (05_API_SPECIFICATION §10).
 *
 * Every method is scoped to the User it is given, which is always the
 * authenticated user resolved by the controller — a favourite is never
 * addressable by anyone but its owner.
 */
final class FavoriteService
{
    /**
     * The one limit `useRecommendations()` (frontend) ever actually requests —
     * see `RecommendationService::songsFor()`'s cache key, which this has to
     * match exactly to invalidate the right entry.
     */
    private const RECOMMENDATIONS_CACHE_LIMIT = 20;

    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly SongRepository $songs,
        private readonly CacheService $cache,
    ) {}

    /** @return LengthAwarePaginator<int, Song> */
    public function paginate(User $user, int $page, int $limit): LengthAwarePaginator
    {
        return $this->favorites->paginateForUser($user, $page, $limit);
    }

    /**
     * @return bool True when the song was newly favourited, false when it
     *              already was. Re-favouriting is not an error: the client's
     *              intended end state is reached either way.
     *
     * @throws ModelNotFoundException
     */
    public function add(User $user, string $songId): bool
    {
        $added = $this->favorites->add($user, $this->songs->findOrFail($songId));
        $this->forgetRecommendations($user);

        return $added;
    }

    /**
     * Idempotent by design: DELETE on a song that is not favourited leaves the
     * user in the state they asked for, so it is not reported as a failure.
     *
     * @throws ModelNotFoundException
     */
    public function remove(User $user, string $songId): void
    {
        $this->favorites->remove($user, $this->songs->findOrFail($songId));
        $this->forgetRecommendations($user);
    }

    /**
     * `RecommendationService::songsFor()` seeds itself from the user's own
     * favourites and caches the result for `music.cache.ttl.recommendations`
     * (30 minutes) — without this, favouriting your first song would leave
     * "Recommended for today" empty until that TTL happened to expire, since
     * nothing else ever invalidated the earlier (correctly empty) result.
     */
    private function forgetRecommendations(User $user): void
    {
        $this->cache->forget('recommendations', "songs:{$user->getKey()}:" . self::RECOMMENDATIONS_CACHE_LIMIT);
    }
}
