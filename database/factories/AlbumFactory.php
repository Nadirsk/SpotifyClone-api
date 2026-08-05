<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Album>
 */
class AlbumFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'artist_id' => Artist::factory(),
            'language_id' => Language::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'cover_image' => 'https://picsum.photos/seed/'.Str::slug($title).'/500/500',
            'release_date' => fake()->dateTimeBetween('-20 years', 'now')->format('Y-m-d'),
            /*
             | Left at zero on purpose: `total_tracks` is denormalized and only
             | means anything once songs exist. Use ->tracks() or let the
             | seeder set it from the songs it actually inserted.
             */
            'total_tracks' => 0,
            'popularity' => fake()->numberBetween(0, 100),
            'trending_score' => fake()->numberBetween(0, 5_000),
            'last_synced_at' => null,
        ];
    }

    public function tracks(int $count): static
    {
        return $this->state(fn (array $attributes): array => [
            'total_tracks' => $count,
        ]);
    }

    public function forArtist(Artist $artist): static
    {
        return $this->state(fn (array $attributes): array => [
            'artist_id' => $artist->getKey(),
        ]);
    }

    public function popular(): static
    {
        return $this->state(fn (array $attributes): array => [
            'popularity' => fake()->numberBetween(80, 100),
            'trending_score' => fake()->numberBetween(5_000, 50_000),
        ]);
    }

    public function withoutLanguage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'language_id' => null,
        ]);
    }
}
