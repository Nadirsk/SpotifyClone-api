<?php

declare(strict_types=1);

namespace App\DTO;

use Illuminate\Support\Collection;

/**
 * One member's music taste, as understood from what this app actually
 * collects: favorites, listening history, and their own playlists
 * (12_SCOPE_OF_WORK §4 B.7/B.8/B.10). No search history and no skip
 * tracking — {@see BlendTasteProfileService}'s own doc explains why those are
 * left out rather than half-implemented.
 *
 * Deliberately not returned to the frontend — BlendGenerationService consumes
 * it and BlendResource never serialises it. That is the "don't expose
 * internal scoring data" boundary from 12_SCOPE_OF_WORK §9.
 */
final readonly class BlendTasteProfile
{
    /**
     * @param  Collection<int, \App\Models\Song>  $favoriteSongs  The actual seed
     *         songs (not just ids) — BlendGenerationService fans these out
     *         through SongRepository::related() for the "discover" candidates.
     * @param  array<string, true>  $favoriteSongIds  Set membership test: `isset($ids[$id])`.
     * @param  array<string, true>  $playedSongIds
     * @param  array<string, float>  $artistScores  artist_id => weight, richest first is not guaranteed; see $topArtistIds.
     * @param  array<string, float>  $genreScores
     * @param  list<string>  $topArtistIds  Sorted, richest affinity first.
     * @param  list<string>  $topGenreIds
     */
    public function __construct(
        public string $userId,
        public Collection $favoriteSongs,
        public array $favoriteSongIds,
        public array $playedSongIds,
        public array $artistScores,
        public array $genreScores,
        public array $topArtistIds,
        public array $topGenreIds,
    ) {}

    /** False for a brand-new account with no favorites and no plays — the empty state BlendGenerationService has to tolerate. */
    public function hasSignal(): bool
    {
        return $this->favoriteSongIds !== [] || $this->playedSongIds !== [];
    }
}
