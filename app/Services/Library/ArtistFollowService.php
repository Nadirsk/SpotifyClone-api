<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\ArtistFollowRepository;
use App\Contracts\Repositories\ArtistRepository;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Artist following (12_SCOPE_OF_WORK.md FR.M — see the migration's docblock).
 *
 * Mirrors FavoriteService exactly: every method is scoped to the User it is
 * given, which is always the authenticated user resolved by the controller.
 */
final class ArtistFollowService
{
    public function __construct(
        private readonly ArtistFollowRepository $follows,
        private readonly ArtistRepository $artists,
    ) {}

    /** @return LengthAwarePaginator<int, Artist> */
    public function paginate(User $user, int $page, int $limit): LengthAwarePaginator
    {
        return $this->follows->paginateForUser($user, $page, $limit);
    }

    /**
     * @return bool True when the artist was newly followed, false when it
     *              already was. Re-following is not an error: the client's
     *              intended end state is reached either way.
     *
     * @throws ModelNotFoundException
     */
    public function follow(User $user, string $artistId): bool
    {
        return $this->follows->add($user, $this->artists->findOrFail($artistId));
    }

    /**
     * Idempotent by design: DELETE on an artist that is not followed leaves
     * the user in the state they asked for, so it is not reported as a failure.
     *
     * @throws ModelNotFoundException
     */
    public function unfollow(User $user, string $artistId): void
    {
        $this->follows->remove($user, $this->artists->findOrFail($artistId));
    }
}
