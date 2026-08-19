<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\History;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface HistoryRepository
{
    /** @return LengthAwarePaginator<int, History> */
    public function paginateForUser(User $user, int $page, int $limit): LengthAwarePaginator;

    /**
     * `$user` is null for a signed-out listener, who is identified by
     * `$sessionId` instead — see the `add_anonymous_play_tracking_to_history`
     * migration for why guest plays are counted at all.
     */
    public function record(?User $user, Song $song, ?int $msPlayed = null, ?string $sessionId = null): History;

    /**
     * Whether this listener already logged this song inside the dedupe window, so
     * a page refresh or a seek does not inflate play counts.
     *
     * Keyed by the account when there is one and by the session otherwise. With
     * neither, there is nobody to deduplicate against and the answer is false.
     */
    public function playedRecently(?User $user, Song $song, int $withinMinutes, ?string $sessionId = null): bool;

    public function clear(User $user): void;

    /**
     * Play counts per song over the trailing window, used to compute trending.
     *
     * @return Collection<string, int> song_id => plays
     */
    public function playCountsSince(\DateTimeInterface $since): Collection;

    /**
     * Song IDs ranked by how many times they were played since $since, most
     * played first — the raw material of the daily chart.
     *
     * Counts plays, and does not decay them: "today" is a closed window, so
     * there is no older tail to discount. Only plays that met the listen
     * threshold are counted; see `config('music.history.min_play_seconds')`.
     *
     * @return Collection<int, string>
     */
    public function topSongIdsSince(\DateTimeInterface $since, int $limit): Collection;
}
