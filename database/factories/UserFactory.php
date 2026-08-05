<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Hashing happens once per process: bcrypt is slow by design and a seeder
     * creating many users would otherwise spend most of its time here.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'avatar' => 'https://picsum.photos/seed/'.Str::slug($name).'/200/200',
            'country' => fake()->countryCode(),
            'language' => fake()->randomElement(['en', 'hi', 'pa', 'ta', 'te', 'ml', 'es', 'ja', 'ko', 'fr', 'de', 'pt']),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Email address left unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * An OAuth-only account, which never sets a local password.
     */
    public function withoutPassword(): static
    {
        return $this->state(fn (array $attributes): array => [
            'password' => null,
        ]);
    }

    public function withoutAvatar(): static
    {
        return $this->state(fn (array $attributes): array => [
            'avatar' => null,
        ]);
    }
}
