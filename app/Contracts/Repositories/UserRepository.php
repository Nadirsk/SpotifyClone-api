<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\User;

interface UserRepository
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): User;

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
