<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\UserFollowRepository;
use App\Models\User;
use App\Models\UserFollow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentUserFollowRepository implements UserFollowRepository
{
    public function paginateFollowers(User $subject, int $page, int $limit): LengthAwarePaginator
    {
        /*
         | Mirrors EloquentFavoriteRepository::paginateForUser() — the endpoint
         | returns users, not follow rows, but the ordering belongs to
         | `user_follows`.
         */
        /*
         | `select()` before `withCount()`, not after: `select()` replaces the
         | query's whole column list, and `withCount()`'s subquery columns are
         | appended via `addSelect()` — the reverse order would silently drop
         | the counts `PublicUserResource::whenCounted()` needs.
         */
        /** @var LengthAwarePaginator<int, User> */
        return User::query()
            ->join('user_follows', 'user_follows.follower_id', '=', 'users.id')
            ->where('user_follows.followed_id', $subject->getKey())
            ->select('users.*')
            ->withCount(['followers', 'following'])
            ->orderByDesc('user_follows.created_at')
            ->orderBy('users.id')
            ->paginate(perPage: $limit, page: $page);
    }

    public function paginateFollowing(User $subject, int $page, int $limit): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, User> */
        return User::query()
            ->join('user_follows', 'user_follows.followed_id', '=', 'users.id')
            ->where('user_follows.follower_id', $subject->getKey())
            ->select('users.*')
            ->withCount(['followers', 'following'])
            ->orderByDesc('user_follows.created_at')
            ->orderBy('users.id')
            ->paginate(perPage: $limit, page: $page);
    }

    public function followersCount(User $subject): int
    {
        return UserFollow::query()->where('followed_id', $subject->getKey())->count();
    }

    public function followingCount(User $subject): int
    {
        return UserFollow::query()->where('follower_id', $subject->getKey())->count();
    }

    public function add(User $follower, User $followed): bool
    {
        if ($this->exists($follower, $followed)) {
            return false;
        }

        UserFollow::query()->create([
            'follower_id' => $follower->getKey(),
            'followed_id' => $followed->getKey(),
        ]);

        return true;
    }

    public function remove(User $follower, User $followed): bool
    {
        return $this->followQuery($follower, $followed)->delete() > 0;
    }

    public function exists(User $follower, User $followed): bool
    {
        return $this->followQuery($follower, $followed)->exists();
    }

    /** @return Builder<UserFollow> */
    private function followQuery(User $follower, User $followed): Builder
    {
        return UserFollow::query()
            ->where('follower_id', $follower->getKey())
            ->where('followed_id', $followed->getKey());
    }
}
