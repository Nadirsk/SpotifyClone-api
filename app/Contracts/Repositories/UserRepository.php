<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserRepository
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): User;

    /**
     * The admin panel's own listing: newest first, with an optional
     * name/email search. Never the target of the public `search` type
     * (PublicUserResource) — this is for account management, so it
     * returns everything UserResource carries, email included.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function adminPaginate(int $page, int $limit, ?string $search): LengthAwarePaginator;

    /**
     * `role` is deliberately absent from `User::$fillable` (see the model),
     * so changing it needs its own method rather than going through
     * `update()`'s mass assignment.
     */
    public function updateRole(User $user, UserRole $role): User;

    public function findByEmail(string $email): ?User;

    public function findByPhone(string $phone): ?User;

    /** @throws \Illuminate\Database\Eloquent\ModelNotFoundException */
    public function findOrFail(string $id): User;

    /** @param  array<string, mixed>  $attributes */
    public function update(User $user, array $attributes): User;

    public function delete(User $user): void;

    /**
     * Finds the user linked to an OAuth identity, or links/creates one.
     * Matching on verified email is what lets an existing password account
     * adopt a Google login instead of creating a duplicate.
     */
    public function findOrCreateFromOauth(
        string $provider,
        string $providerUserId,
        string $email,
        string $name,
        ?string $avatar,
    ): User;
}
