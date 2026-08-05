<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word());

        return [
            'name' => $name,
            'api_name' => Str::snake(Str::lower($name)),
            // Off by default: a provider is only live once its credentials are.
            'enabled' => false,
            'priority' => fake()->numberBetween(1, 100),
            'last_synced_at' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => true,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_synced_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ]);
    }
}
