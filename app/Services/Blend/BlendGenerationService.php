<?php

declare(strict_types=1);

namespace App\Services\Blend;

use App\Contracts\Repositories\BlendRepository;
use App\Contracts\Repositories\SongRepository;
use App\DTO\BlendTasteProfile;
use App\Enums\BlendReason;
use App\Exceptions\DomainException;
use App\Models\Blend;
use App\Models\Song;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns two or more {@see BlendTasteProfile}s into a ranked, generated
 * tracklist — the one place 12_SCOPE_OF_WORK §10-§14 and §31's "transparent
 * scoring, not Spotify's algorithm" actually get implemented.
 *
 * ## The score
 *
 * Every candidate song accumulates, per member: a bonus for being one of
 * their favorites, a smaller one for being in their recent history, and a
 * smaller still one for matching one of their top artists/genres by taste
 * weight (see `BlendTasteProfileService`). A song that matches two or more
 * *distinct* members this way earns one flat "shared taste" bonus on top —
 * that is §11's "Song B should receive a strong shared taste score", made
 * concrete. A small freshness and popularity term breaks ties among
 * otherwise-equal candidates; neither is large enough to outrank an actual
 * taste match.
 *
 * ## The balance
 *
 * §12 asks for a mix of both members' individual taste, not just their
 * overlap, and explicitly not a naive alternation. `rank()` answers that by
 * bucketing into "shared" / "one member's taste, per member" / "discovery",
 * sorting each bucket by score, and round-robining the per-member buckets
 * against each other — so the final order favors shared picks first, then
 * gives each member a fair, score-ordered share of the middle, then fills
 * the tail with catalog discovery.
 *
 * ## What this is not
 *
 * Not ML, and not Spotify's own algorithm — every weight below is a plain
 * constant over data this app already collects, on purpose (§31).
 */
final class BlendGenerationService
{
    private const FAVORITE_BONUS = 40.0;

    private const HISTORY_BONUS = 20.0;

    private const ARTIST_AFFINITY_BONUS = 10.0;

    private const GENRE_AFFINITY_BONUS = 5.0;

    /** Awarded once, on top of the per-member bonuses above, when 2+ distinct members match a song. */
    private const SHARED_TASTE_BONUS = 25.0;

    /** A release earns this at 0 months old, decaying to 0 by month 30. */
    private const FRESHNESS_MAX_BONUS = 5.0;

    private const FRESHNESS_DECAY_MONTHS = 6.0;

    /** popularity (0-100) * this — up to 2 points, just enough to break ties. */
    private const POPULARITY_WEIGHT = 0.02;

    /** How many of each member's own favorites seed the discovery fan-out. */
    private const DISCOVERY_SEED_SAMPLE = 10;

    public function __construct(
        private readonly BlendRepository $blends,
        private readonly BlendTasteProfileService $tasteProfiles,
        private readonly SongRepository $songs,
    ) {}

    /**
     * @throws DomainException When $blend has fewer than two members.
     */
    public function generate(Blend $blend): void
    {
        /** @var Collection<int, User> $members */
        $members = $this->blends->members($blend)->pluck('user')->filter()->values();

        if ($members->count() < 2) {
            throw DomainException::blendNotYetActive();
        }

        $profiles = $members->map(fn (User $user): BlendTasteProfile => $this->tasteProfiles->profileFor($user));

        $candidates = $this->collectCandidates($profiles);

        $scored = $candidates->map(
            fn (array $candidate) => $this->score($candidate['song'], $candidate['sourceMemberId'], $profiles),
        );

        $maxTracks = (int) config('music.blends.max_tracks');

        $rows = $this->rank($scored, $profiles)
            ->take($maxTracks)
            ->values()
            ->map(fn (array $candidate, int $position): array => [
                'song_id' => (string) $candidate['song']->getKey(),
                'score' => $candidate['score'],
                'reason' => $candidate['reason']->value,
                'attributed_user_id' => $candidate['attributedUserId'],
                'position' => $position,
            ])
            ->all();

        $this->blends->replaceSongs($blend, $rows, $this->matchScore($profiles));
    }

    /**
     * @param  Collection<int, BlendTasteProfile>  $profiles
     * @return Collection<int, array{song: Song, sourceMemberId: ?string}>
     */
    private function collectCandidates(Collection $profiles): Collection
    {
        $directIds = [];

        foreach ($profiles as $profile) {
            foreach (array_keys($profile->favoriteSongIds) as $id) {
                $directIds[$id] = true;
            }

            foreach (array_keys($profile->playedSongIds) as $id) {
                $directIds[$id] = true;
            }
        }

        /*
         | song id => ['song' => Song, 'sourceMemberId' => ?string]. A direct
         | favorite/history candidate carries no single source — `score()`
         | attributes those from `matchedMemberIds` instead — but a pure
         | catalog-discovery pick has no favorite/history match at all, and
         | this is the only place that still knows which member's seed
         | surfaced it (first touch wins, same as the pool's own dedup).
         */
        $pool = [];

        foreach ($this->songs->findMany(array_keys($directIds)) as $song) {
            $pool[(string) $song->getKey()] = ['song' => $song, 'sourceMemberId' => null];
        }

        $discoveryPerSeed = (int) config('music.blends.discovery_per_seed');

        foreach ($profiles as $profile) {
            foreach ($profile->favoriteSongs->take(self::DISCOVERY_SEED_SAMPLE) as $seed) {
                foreach ($this->songs->related($seed, $discoveryPerSeed) as $related) {
                    $id = (string) $related->getKey();

                    if (! isset($pool[$id])) {
                        $pool[$id] = ['song' => $related, 'sourceMemberId' => $profile->userId];
                    }
                }
            }
        }

        return collect(array_values($pool));
    }

    /**
     * @param  Collection<int, BlendTasteProfile>  $profiles
     * @return array{song: Song, score: float, reason: BlendReason, matchedMemberIds: list<string>, attributedUserId: ?string}
     */
    private function score(Song $song, ?string $sourceMemberId, Collection $profiles): array
    {
        $songId = (string) $song->getKey();
        $score = 0.0;
        $matchedMemberIds = [];

        foreach ($profiles as $profile) {
            $matched = false;

            if (isset($profile->favoriteSongIds[$songId])) {
                $score += self::FAVORITE_BONUS;
                $matched = true;
            }

            if (isset($profile->playedSongIds[$songId])) {
                $score += self::HISTORY_BONUS;
                $matched = true;
            }

            if ($song->artist_id !== null && in_array($song->artist_id, $profile->topArtistIds, true)) {
                $score += self::ARTIST_AFFINITY_BONUS;
                $matched = true;
            }

            if ($song->genre_id !== null && in_array($song->genre_id, $profile->topGenreIds, true)) {
                $score += self::GENRE_AFFINITY_BONUS;
                $matched = true;
            }

            if ($matched) {
                $matchedMemberIds[] = $profile->userId;
            }
        }

        $reason = match (true) {
            count($matchedMemberIds) >= 2 => BlendReason::Shared,
            count($matchedMemberIds) === 1 => BlendReason::Taste,
            default => BlendReason::Discover,
        };

        if ($reason === BlendReason::Shared) {
            $score += self::SHARED_TASTE_BONUS;
        }

        $score += $this->freshnessBonus($song);
        $score += ((float) $song->popularity) * self::POPULARITY_WEIGHT;

        /*
         | "Added by" — one member per row, mirroring real Spotify's own
         | Blend tracklist. A shared or single-member taste match always has
         | at least one matched member to credit; a pure discovery pick
         | credits whichever member's favorite seeded the related() lookup
         | that surfaced it, and has nobody to credit if even that is unknown.
         */
        $attributedUserId = $matchedMemberIds[0] ?? $sourceMemberId;

        return [
            'song' => $song,
            'score' => $score,
            'reason' => $reason,
            'matchedMemberIds' => $matchedMemberIds,
            'attributedUserId' => $attributedUserId,
        ];
    }

    private function freshnessBonus(Song $song): float
    {
        if ($song->release_date === null) {
            return 0.0;
        }

        $monthsSince = $song->release_date->diffInMonths(now());

        return max(0.0, self::FRESHNESS_MAX_BONUS - ($monthsSince / self::FRESHNESS_DECAY_MONTHS));
    }

    /**
     * Shared picks first, then a score-ordered round robin across each
     * member's own top taste, then catalog discovery — see this class's own
     * doc for why a plain score sort alone is not what §12 asks for.
     *
     * @param  Collection<int, array{song: Song, score: float, reason: BlendReason, matchedMemberIds: list<string>, attributedUserId: ?string}>  $scored
     * @param  Collection<int, BlendTasteProfile>  $profiles
     * @return Collection<int, array{song: Song, score: float, reason: BlendReason, matchedMemberIds: list<string>, attributedUserId: ?string}>
     */
    private function rank(Collection $scored, Collection $profiles): Collection
    {
        $shared = $scored->filter(fn (array $c): bool => $c['reason'] === BlendReason::Shared)
            ->sortByDesc('score')
            ->values();

        $discover = $scored->filter(fn (array $c): bool => $c['reason'] === BlendReason::Discover)
            ->sortByDesc('score')
            ->values();

        /** @var array<string, Collection<int, array{song: Song, score: float, reason: BlendReason, matchedMemberIds: list<string>, attributedUserId: ?string}>> $perMember */
        $perMember = [];

        foreach ($profiles as $profile) {
            $perMember[$profile->userId] = $scored
                ->filter(fn (array $c): bool => $c['reason'] === BlendReason::Taste && ($c['matchedMemberIds'][0] ?? null) === $profile->userId)
                ->sortByDesc('score')
                ->values();
        }

        $interleaved = collect();
        $cursor = array_fill_keys(array_keys($perMember), 0);
        $memberIds = array_keys($perMember);

        do {
            $addedThisRound = false;

            foreach ($memberIds as $memberId) {
                $bucket = $perMember[$memberId];
                $index = $cursor[$memberId];

                if ($bucket->has($index)) {
                    $interleaved->push($bucket->get($index));
                    $cursor[$memberId]++;
                    $addedThisRound = true;
                }
            }
        } while ($addedThisRound);

        return $shared->concat($interleaved)->concat($discover);
    }

    /**
     * A transparent 0-100 "taste match" — averaged, pairwise Jaccard overlap
     * of favorited songs, top artists and top genres. Deliberately a
     * different number from any `blend_songs.score`: this answers "how alike
     * are these people", not "how good is this song pick" (§14).
     *
     * @param  Collection<int, BlendTasteProfile>  $profiles
     */
    private function matchScore(Collection $profiles): ?int
    {
        $list = $profiles->values();
        $pairCount = $list->count();

        if ($pairCount < 2) {
            return null;
        }

        $overlaps = [];

        for ($i = 0; $i < $pairCount; $i++) {
            for ($j = $i + 1; $j < $pairCount; $j++) {
                $a = $list[$i];
                $b = $list[$j];

                $songOverlap = $this->jaccard(array_keys($a->favoriteSongIds), array_keys($b->favoriteSongIds));
                $artistOverlap = $this->jaccard($a->topArtistIds, $b->topArtistIds);
                $genreOverlap = $this->jaccard($a->topGenreIds, $b->topGenreIds);

                $overlaps[] = (0.5 * $songOverlap) + (0.3 * $artistOverlap) + (0.2 * $genreOverlap);
            }
        }

        return (int) round((array_sum($overlaps) / count($overlaps)) * 100);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 0.0;
        }

        $union = array_unique(array_merge($a, $b));

        if ($union === []) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count($union);
    }
}
