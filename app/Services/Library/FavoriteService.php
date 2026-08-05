<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\FavoriteRepository;
use App\Contracts\Repositories\SongRepository;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Favourites (05_API_SPECIFICATION §10).
 *
 * Every method is scoped to the User it is given, which is always the
 * authenticated user resolved by the controller — a favourite is never
 * addressable by anyone but its owner.
 */
final class FavoriteService
{
    public function __construct(
        private readonly FavoriteRepository $favorites,
        private readonly SongRepository $songs,
    ) {}

    /** @return LengthAwarePaginator<int, Song> */
    public function paginate(User $user, int $page, int $limit): LengthAwarePaginator
    {
        return $this->favorites->paginateForUser($user, $page, $limit);
    }

    /**
     * @return bool True when the song was newly favourited, false when it
     *              already was. Re-favouriting is not an error: the client's
     *              intended end state is reached either way.
     *
     * @throws ModelNotFoundException
     */
    public function add(User $user, string $songId): bool
    {
        return $this->favorites->add($user, $this->songs->findOrFail($songId));
    }

    /**
     * Idempotent by design: DELETE on a song that is not favourited leaves the
     * user in the state they asked for, so it is not reported as a failure.
     *
     * @throws ModelNotFoundException
     */
    public function remove(User $user, string $songId): void
    {
        $this->favorites->remove($user, $this->songs->findOrFail($songId));
    }
}
