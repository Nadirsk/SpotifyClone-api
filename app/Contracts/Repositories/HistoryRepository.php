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

    public function record(User $user, Song $song, ?int $msPlayed = null): History;

    /**
     * Whether this user already logged this song inside the dedupe window, so a
     * page refresh or a seek does not inflate play counts.
     */
    public function playedRecently(User $user, Song $song, int $withinMinutes): bool;

    public function clear(User $user): void;

    /**
     * Play counts per song over the trailing window, used to compute trending.
     *
     * @return Collection<string, int> song_id => plays
     */
    public function playCountsSince(\DateTimeInterface $since): Collection;
}
