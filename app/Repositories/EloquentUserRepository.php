<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\UserRepository;
use App\Enums\UserRole;
use App\Models\OauthAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentUserRepository implements UserRepository
{
    public function create(array $attributes): User
    {
        /** @var User */
        return User::query()->create($attributes);
    }

    public function adminPaginate(int $page, int $limit, ?string $search): LengthAwarePaginator
    {
        $builder = User::query()->orderByDesc('created_at');

        if ($search !== null && $search !== '') {
            $builder->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        /** @var LengthAwarePaginator<int, User> */
        return $builder->paginate(perPage: $limit, page: $page);
    }

    public function updateRole(User $user, UserRole $role): User
    {
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function findByPhone(string $phone): ?User
    {
        return User::query()->where('phone', $phone)->first();
    }

    public function findOrFail(string $id): User
    {
        /** @var User */
        return User::query()->findOrFail($id);
    }

    public function update(User $user, array $attributes): User
    {
        $user->fill($attributes)->save();

        return $user;
    }

    /**
     * `email` and `phone` both carry a hard DB-level unique index that knows
     * nothing about soft deletes, so a deleted user's original email/phone
     * would otherwise block that same address from ever registering again
     * (or the same person from signing back up). Freeing them here — instead
     * of just leaving them for the `unique` validation rule to worry about —
     * means the value is gone from the table the moment it's deleted, not
     * just hidden from Eloquent's default query scope.
     */
    public function delete(User $user): void
    {
        $user->fill([
            'email' => self::freeUpValue($user->email),
            // Nullable and only 20 chars wide — no room for a traceable
            // prefix like email gets, so this just frees the slot outright
            // (MySQL allows more than one NULL in a unique index).
            'phone' => null,
        ])->save();

        $user->delete();
    }

    private static function freeUpValue(string $value): string
    {
        $mangled = sprintf('deleted+%d+%s', now()->timestamp, $value);

        return mb_substr($mangled, 0, 255);
    }

    public function findOrCreateFromOauth(
        string $provider,
        string $providerUserId,
        string $email,
        string $name,
        ?string $avatar,
    ): User {
        return DB::transaction(function () use ($provider, $providerUserId, $email, $name, $avatar): User {
            $linked = OauthAccount::query()
                ->with('user')
                ->where('provider', $provider)
                ->where('provider_user_id', $providerUserId)
                ->first()?->user;

            if ($linked !== null) {
                return $linked;
            }

            /*
             | No link yet. Matching on email lets someone who signed up with a
             | password adopt the social login instead of ending up with two
             | accounts — the provider only hands us verified addresses.
             */
            $user = $this->findByEmail($email) ?? $this->createOauthUser($email, $name, $avatar);

            $user->oauthAccounts()->create([
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
            ]);

            return $user;
        });
    }

    private function createOauthUser(string $email, string $name, ?string $avatar): User
    {
        $user = new User([
            'name' => $name,
            'email' => $email,
            // OAuth-only account: there is no local credential to hash.
            'password' => null,
            'avatar' => $avatar,
        ]);

        // Not fillable, and set directly because the provider has already
        // verified the address — our own verification flow has nothing to add.
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }
}
