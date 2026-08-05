<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FavoriteRepository
{
    /**
     * The user's favourited songs, newest first.
     *
     * @return LengthAwarePaginator<int, Song>
     */
    public function paginateForUser(User $user, int $page, int $limit): LengthAwarePaginator;

    /** Returns false when the song was already favourited. */
    public function add(User $user, Song $song): bool;

    /** Returns false when the song was not favourited. */
    public function remove(User $user, Song $song): bool;

    public function exists(User $user, Song $song): bool;

    /**
     * Which of $songIds the user has favourited, so a listing can flag them
     * without one query per row.
     *
     * @param  list<string>  $songIds
     * @return list<string>
     */
    public function favoritedIds(User $user, array $songIds): array;
}
