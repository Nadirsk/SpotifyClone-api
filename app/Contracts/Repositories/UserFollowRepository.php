<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserFollowRepository
{
    /**
     * Who follows $subject, newest first.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateFollowers(User $subject, int $page, int $limit): LengthAwarePaginator;

    /**
     * Who $subject follows, newest first.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateFollowing(User $subject, int $page, int $limit): LengthAwarePaginator;

    public function followersCount(User $subject): int;

    public function followingCount(User $subject): int;

    /** Returns false when $follower already followed $followed. */
    public function add(User $follower, User $followed): bool;

    /** Returns false when $follower did not follow $followed. */
    public function remove(User $follower, User $followed): bool;

    public function exists(User $follower, User $followed): bool;
}
