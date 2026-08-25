<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Contracts\Repositories\UserRepository;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Account management for the admin panel — a different subject than
 * {@see ProfileService}, which only ever acts on the token's own user.
 */
final class AdminUserService
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        return $this->users->adminPaginate($page, $limit, $search);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): User
    {
        return $this->users->findOrFail($id);
    }

    public function updateRole(User $user, string $role): User
    {
        return $this->users->updateRole($user, UserRole::from($role));
    }

    /**
     * Tokens are revoked explicitly, the same as a listener deleting their
     * own account ({@see ProfileService::delete()}) — otherwise a restored
     * account's old bearer tokens would come back to life with it.
     */
    public function delete(User $user): void
    {
        $user->tokens()->delete();

        $this->users->delete($user);
    }
}
