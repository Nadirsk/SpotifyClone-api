<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\UserRepository;
use App\Models\OauthAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EloquentUserRepository implements UserRepository
{
    public function create(array $attributes): User
    {
        /** @var User */
        return User::query()->create($attributes);
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

    public function delete(User $user): void
    {
        $user->delete();
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
