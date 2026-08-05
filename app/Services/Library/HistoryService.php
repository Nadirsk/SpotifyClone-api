<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\HistoryRepository;
use App\Contracts\Repositories\SongRepository;
use App\Models\History;
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
     * Logs a play, unless the same user played the same song inside the dedupe
     * window — a refresh, a seek or a replayed intro would otherwise inflate
     * both the history feed and the song's play_count.
     *
     * @return History|null Null when the play was deliberately skipped.
     *
     * @throws ModelNotFoundException
     */
    public function record(User $user, string $songId, ?int $msPlayed = null): ?History
    {
        $song = $this->songs->findOrFail($songId);
        $window = (int) config('music.history.dedupe_window_minutes');

        if ($this->history->playedRecently($user, $song, $window)) {
            return null;
        }

        $entry = $this->history->record($user, $song, $msPlayed);

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
}
