<?php

declare(strict_types=1);

namespace App\Services\Blend;

use App\Contracts\Repositories\FavoriteRepository;
use App\Contracts\Repositories\HistoryRepository;
use App\Contracts\Repositories\PlaylistRepository;
use App\DTO\BlendTasteProfile;
use App\Models\Song;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds one member's {@see BlendTasteProfile} from real, already-collected
 * activity — no ML, same "no ceremony" approach as `RecommendationService`.
 *
 * Three signals, weighted by how deliberate they are:
 *
 * - **Favorites** (strongest) — an explicit "I like this."
 * - **Recent history** (medium) — actually listened to, but not necessarily loved.
 * - **Own playlists** (lightest) — a song curated into *something*, but a
 *   playlist mixes moods and eras more than a favorites list does.
 *
 * Search history and skips are deliberately excluded even though
 * 12_SCOPE_OF_WORK §8 lists them as available "weak signals" in the abstract:
 * `search_history` only records the typed keyword, not which result (if any)
 * the searcher meant, and this app records no skip events at all — there is
 * no `skipped_at` column anywhere in `03_DATABASE_DESIGN`. Wiring in a signal
 * this noisy, or inventing one that isn't collected, would fail
 * "don't use data the application doesn't actually collect" in spirit even
 * where a keyword technically exists in a table.
 */
final class BlendTasteProfileService
{
    private const FAVORITE_WEIGHT = 3.0;

    private const HISTORY_WEIGHT = 2.0;

    private const PLAYLIST_WEIGHT = 1.0;

    /** How many playlists, and how many songs per playlist, seed the lightest signal. */
    private const PLAYLIST_SAMPLE = 3;

    private const PLAYLIST_SONGS_PER_SAMPLE = 15;

    /** How many top artists/genres `topArtistIds`/`topGenreIds` keep. */
    private const TOP_TAXONOMY_COUNT = 10;

    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly HistoryRepository $history,
        private readonly PlaylistRepository $playlists,
    ) {}

    public function profileFor(User $user): BlendTasteProfile
    {
        $sampleSize = (int) config('music.blends.taste_sample_size');

        /** @var Collection<int, Song> $favoriteSongs */
        $favoriteSongs = collect($this->favorites->paginateForUser($user, 1, $sampleSize)->items());

        $historySongs = collect($this->history->paginateForUser($user, 1, $sampleSize)->items())
            ->map(fn ($entry) => $entry->song)
            ->filter();

        $playlistSongs = collect($this->playlists->paginateForOwner($user, 1, self::PLAYLIST_SAMPLE)->items())
            ->flatMap(fn ($playlist) => $playlist->songs()->limit(self::PLAYLIST_SONGS_PER_SAMPLE)->get());

        $artistScores = [];
        $genreScores = [];

        $this->accumulate($favoriteSongs, self::FAVORITE_WEIGHT, $artistScores, $genreScores);
        $this->accumulate($historySongs, self::HISTORY_WEIGHT, $artistScores, $genreScores);
        $this->accumulate($playlistSongs, self::PLAYLIST_WEIGHT, $artistScores, $genreScores);

        arsort($artistScores);
        arsort($genreScores);

        return new BlendTasteProfile(
            userId: (string) $user->getKey(),
            favoriteSongs: $favoriteSongs->values(),
            favoriteSongIds: $this->idSet($favoriteSongs),
            playedSongIds: $this->idSet($historySongs),
            artistScores: $artistScores,
            genreScores: $genreScores,
            topArtistIds: array_slice(array_keys($artistScores), 0, self::TOP_TAXONOMY_COUNT),
            topGenreIds: array_slice(array_keys($genreScores), 0, self::TOP_TAXONOMY_COUNT),
        );
    }

    /**
     * @param  Collection<int, Song>  $songs
     * @param  array<string, float>  $artistScores
     * @param  array<string, float>  $genreScores
     */
    private function accumulate(Collection $songs, float $weight, array &$artistScores, array &$genreScores): void
    {
        foreach ($songs as $song) {
            if ($song->artist_id !== null) {
                $artistScores[$song->artist_id] = ($artistScores[$song->artist_id] ?? 0.0) + $weight;
            }

            if ($song->genre_id !== null) {
                $genreScores[$song->genre_id] = ($genreScores[$song->genre_id] ?? 0.0) + $weight;
            }
        }
    }

    /**
     * @param  Collection<int, Song>  $songs
     * @return array<string, true>
     */
    private function idSet(Collection $songs): array
    {
        $ids = [];

        foreach ($songs as $song) {
            $ids[(string) $song->getKey()] = true;
        }

        return $ids;
    }
}
