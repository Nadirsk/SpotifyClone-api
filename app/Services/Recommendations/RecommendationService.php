<?php

declare(strict_types=1);

namespace App\Services\Recommendations;

use App\Contracts\Repositories\FavoriteRepository;
use App\Contracts\Repositories\SongRepository;
use App\Models\Song;
use App\Models\User;
use App\Services\Cache\CacheService;
use Illuminate\Support\Collection;

/**
 * "Recommended for you": no ML, no separate scoring table — just the same
 * "songs like this one" lookup the catalog already uses for a song's own
 * related-tracks shelf (`SongRepository::related()`), fanned out over a
 * sample of the user's own favourites instead of over one song's page.
 *
 * Deliberately songs only, not the three-endpoint shape (`/recommendations`,
 * `/recommendations/artists`, `/recommendations/albums`) some references
 * describe — one coherent shelf is what the frontend actually needs, and a
 * second scoring path for artists/albums would duplicate this one for no
 * requested behaviour.
 */
final class RecommendationService
{
    /** How many of the user's own favourites seed the lookup. */
    private const SEED_SAMPLE = 10;

    /** How many related songs each seed contributes before merging. */
    private const PER_SEED_CANDIDATES = 5;

    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly SongRepository $songs,
        private readonly CacheService $cache,
    ) {}

    /**
     * @return Collection<int, Song>
     */
    public function songsFor(User $user, ?int $limit = null): Collection
    {
        $limit = $this->resolveLimit($limit);

        return $this->cache->remember(
            'recommendations',
            "songs:{$user->getKey()}:{$limit}",
            fn (): Collection => $this->build($user, $limit),
        );
    }

    /**
     * @return Collection<int, Song>
     */
    private function build(User $user, int $limit): Collection
    {
        $seeds = $this->favorites->paginateForUser($user, 1, self::SEED_SAMPLE)->items();

        if ($seeds === []) {
            return new Collection;
        }

        $favoritedIds = collect($seeds)->map(fn (Song $song): string => (string) $song->getKey());

        $candidates = collect($seeds)
            ->flatMap(fn (Song $seed): Collection => $this->songs->related($seed, self::PER_SEED_CANDIDATES));

        return $candidates
            ->unique(fn (Song $song): string => (string) $song->getKey())
            ->reject(fn (Song $song): bool => $favoritedIds->contains((string) $song->getKey()))
            ->sortByDesc('popularity')
            ->take($limit)
            ->values();
    }

    /**
     * Clamped to the pagination ceiling, same reasoning as
     * `TrendingService::resolveLimit()`.
     */
    private function resolveLimit(?int $limit): int
    {
        $default = (int) config('music.trending.limit');
        $max = (int) config('music.pagination.max_limit');

        return min($max, max(1, $limit ?? $default));
    }
}
