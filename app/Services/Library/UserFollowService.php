<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\UserFollowRepository;
use App\Contracts\Repositories\UserRepository;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * User-to-user following (12_SCOPE_OF_WORK.md has no source for this — see
 * the migration's docblock). Public reads (`show`, the two paginated lists)
 * are not scoped to the caller: anyone can view anyone's public profile and
 * follower/following lists, same as the reference product.
 */
final class UserFollowService
{
    public function __construct(
        private readonly UserFollowRepository $follows,
        private readonly UserRepository $users,
    ) {}

    /** @throws ModelNotFoundException */
    public function show(string $userId): User
    {
        $user = $this->users->findOrFail($userId);
        $user->loadCount(['followers', 'following']);

        return $user;
    }

    /**
     * @return LengthAwarePaginator<int, User>
     *
     * @throws ModelNotFoundException
     */
    public function paginateFollowers(string $userId, int $page, int $limit): LengthAwarePaginator
    {
        return $this->follows->paginateFollowers($this->users->findOrFail($userId), $page, $limit);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     *
     * @throws ModelNotFoundException
     */
    public function paginateFollowing(string $userId, int $page, int $limit): LengthAwarePaginator
    {
        return $this->follows->paginateFollowing($this->users->findOrFail($userId), $page, $limit);
    }

    /**
     * @return bool True when the follow was new, false when it already
     *              existed — re-following is not an error.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function follow(User $follower, string $followedId): bool
    {
        $followed = $this->users->findOrFail($followedId);

        if ($followed->is($follower)) {
            throw ValidationException::withMessages(['id' => 'You cannot follow yourself.']);
        }

        return $this->follows->add($follower, $followed);
    }

    /**
     * Idempotent by design, same as unfavoriting a song that was never
     * favorited.
     *
     * @throws ModelNotFoundException
     */
    public function unfollow(User $follower, string $followedId): void
    {
        $this->follows->remove($follower, $this->users->findOrFail($followedId));
    }
}
