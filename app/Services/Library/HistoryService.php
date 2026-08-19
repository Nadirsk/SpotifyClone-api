<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\HistoryRepository;
use App\Contracts\Repositories\SongRepository;
use App\Models\History;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Listening history (05_API_SPECIFICATION §11).
 *
 * Scoped to the authenticated user the controller passes in — history is never
 * readable or writable across accounts.
 */
final class HistoryService
{
    /**
     * Loaded onto the song before it is handed to SongResource, because
     * `Model::preventLazyLoading()` turns a missed relation into a 500.
     *
     * @var list<string>
     */
    private const SONG_RELATIONS = ['artist', 'album', 'genre', 'language'];

    public function __construct(
        private readonly HistoryRepository $history,
        private readonly SongRepository $songs,
    ) {}

    /** @return LengthAwarePaginator<int, History> */
    public function paginate(User $user, int $page, int $limit): LengthAwarePaginator
    {
        return $this->history->paginateForUser($user, $page, $limit);
    }

    /**
     * Logs a play.
     *
     * Skipped in two cases, both of which are successes as far as the caller is
     * concerned — the client did nothing wrong, the play simply does not count:
     *
     * 1. **Too short.** A reported listen below the threshold is a skip, not a
     *    play. Without this every chart built on this table measured how fast
     *    people click through an album. The client applies the same rule before
     *    it sends, but a play count is not something to take a client's word
     *    for, so it is enforced here as well.
     * 2. **Already counted.** The same listener played the same song inside the
     *    dedupe window — a refresh, a seek, or a replayed intro.
     *
     * `$user` null is a signed-out listener, deduped on `$sessionId` instead.
     *
     * @return History|null Null when the play was deliberately skipped.
     *
     * @throws ModelNotFoundException
     */
    public function record(?User $user, string $songId, ?int $msPlayed = null, ?string $sessionId = null): ?History
    {
        $song = $this->songs->findOrFail($songId);

        if (! $this->longEnoughToCount($song, $msPlayed)) {
            return null;
        }

        $window = (int) config('music.history.dedupe_window_minutes');

        if ($this->history->playedRecently($user, $song, $window, $sessionId)) {
            return null;
        }

        $entry = $this->history->record($user, $song, $msPlayed, $sessionId);

        // play_count only moves for plays that actually got recorded, so the
        // counter and the history table can never disagree.
        $this->songs->incrementPlayCount($song);

        // The song is already in memory; attaching it avoids a lazy load when
        // the resource serialises the new entry.
        return $entry->setRelation('song', $song->loadMissing(self::SONG_RELATIONS));
    }

    public function clear(User $user): void
    {
        $this->history->clear($user);
    }

    /**
     * Whether enough of the track was heard for this to be a play.
     *
     * Two thresholds, whichever is reached first: an absolute number of seconds,
     * and a fraction of the track. The absolute one alone would make a 40-second
     * interlude uncountable without playing it almost twice over; the fraction
     * alone would count six seconds of a twelve-second sting.
     *
     * A null `$msPlayed` counts. Clients that do not report a duration are not
     * assumed to be skipping — this is the same tolerance
     * `EloquentHistoryRepository::topSongIdsSince()` extends to the rows written
     * before the threshold existed.
     */
    private function longEnoughToCount(Song $song, ?int $msPlayed): bool
    {
        if ($msPlayed === null) {
            return true;
        }

        $seconds = $msPlayed / 1000;
        $absolute = (float) config('music.history.min_play_seconds', 30);
        $fraction = (float) config('music.history.min_play_fraction', 0.5);

        if ($seconds >= $absolute) {
            return true;
        }

        $duration = (int) $song->duration;

        // A song with no known duration has no fraction to compare against, so
        // the absolute threshold above is the only test it can be given.
        return $duration > 0 && $seconds >= $duration * $fraction;
    }
}
