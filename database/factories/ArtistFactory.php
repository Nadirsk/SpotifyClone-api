<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Artist>
 */
class ArtistFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'bio' => fake()->paragraph(),
            'image' => 'https://picsum.photos/seed/'.Str::slug($name).'/400/400',
            'country' => fake()->countryCode(),
            'popularity' => fake()->numberBetween(0, 100),
            'trending_score' => fake()->numberBetween(0, 5_000),
            'last_synced_at' => null,
        ];
    }

    public function popular(): static
    {
        return $this->state(fn (array $attributes): array => [
            'popularity' => fake()->numberBetween(80, 100),
            'trending_score' => fake()->numberBetween(5_000, 50_000),
        ]);
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_synced_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function fromCountry(string $country): static
    {
        return $this->state(fn (array $attributes): array => [
            'country' => $country,
        ]);
    }
}
