<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ArtistFollowRepository
{
    /**
     * The user's followed artists, newest first.
     *
     * @return LengthAwarePaginator<int, Artist>
     */
    public function paginateForUser(User $user, int $page, int $limit): LengthAwarePaginator;

    /** Returns false when the artist was already followed. */
    public function add(User $user, Artist $artist): bool;

    /** Returns false when the artist was not followed. */
    public function remove(User $user, Artist $artist): bool;

    public function exists(User $user, Artist $artist): bool;
}
