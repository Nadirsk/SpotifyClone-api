<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The only way to create or promote an admin account. `role` is deliberately
 * absent from User::$fillable, so mass assignment (register, seeders' normal
 * updateOrCreate) can never grant it — this command is the one place that
 * calls `forceFill()` for it.
 */
final class MakeAdminUser extends Command
{
    protected $signature = 'admin:create {email} {--name=Admin} {--password= : Plaintext password; a random one is generated and printed if omitted}';

    protected $description = 'Create or promote a user to the admin role for the admin panel';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = $this->option('password') ?: Str::random(16);

        $user = User::withTrashed()->firstWhere('email', $email);

        if ($user === null) {
            $user = new User([
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => $password,
            ]);
        } else {
            $user->password = $password;
        }

        $user->forceFill(['role' => UserRole::Admin]);
        $user->save();

        $this->info("Admin ready: {$email}");

        if (! $this->option('password')) {
            $this->warn("Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
