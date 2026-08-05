<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Song>
 */
class SongFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));
        $slug = Str::slug($title);

        return [
            'artist_id' => Artist::factory(),
            /*
             | A song defaults to a single. Attaching an album here would risk
             | pairing it with an album belonging to a different artist, which
             | the catalog must never contain — use ->onAlbum() instead.
             */
            'album_id' => null,
            'genre_id' => Genre::factory(),
            'language_id' => Language::factory(),
            'title' => $title,
            'slug' => $slug,
            // Meaningless without an album; ->onAlbum() sets it.
            'track_number' => null,
            'duration' => fake()->numberBetween(120, 360),
            'isrc' => self::isrc(),
            'release_date' => fake()->dateTimeBetween('-20 years', 'now')->format('Y-m-d'),
            'popularity' => fake()->numberBetween(0, 100),
            'trending_score' => fake()->numberBetween(0, 5_000),
            'play_count' => fake()->numberBetween(0, 500_000),
            'preview_url' => 'https://previews.example.test/'.$slug.'.mp3',
            'external_url' => 'https://listen.example.test/track/'.$slug,
            'last_synced_at' => null,
        ];
    }

    /**
     * Place the song on an album, inheriting that album's artist and language
     * so the pair stays consistent.
     */
    public function onAlbum(Album $album, ?int $trackNumber = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'album_id' => $album->getKey(),
            'artist_id' => $album->artist_id,
            'language_id' => $album->language_id,
            'release_date' => $album->release_date?->format('Y-m-d') ?? $attributes['release_date'],
            'track_number' => $trackNumber,
        ]);
    }

    public function single(): static
    {
        return $this->state(fn (array $attributes): array => [
            'album_id' => null,
            'track_number' => null,
        ]);
    }

    public function popular(): static
    {
        return $this->state(fn (array $attributes): array => [
            'popularity' => fake()->numberBetween(80, 100),
            'trending_score' => fake()->numberBetween(5_000, 50_000),
            'play_count' => fake()->numberBetween(500_000, 5_000_000),
        ]);
    }

    public function withoutIsrc(): static
    {
        return $this->state(fn (array $attributes): array => [
            'isrc' => null,
        ]);
    }

    /**
     * A structurally plausible ISRC: 2-letter country, 3-character registrant,
     * 2-digit year of reference, 5-digit designation.
     */
    private static function isrc(): string
    {
        return sprintf(
            '%s%s%02d%05d',
            fake()->randomElement(['US', 'GB', 'IN', 'FR', 'DE', 'JP', 'KR', 'ES', 'BR', 'SE']),
            fake()->regexify('[A-Z0-9]{3}'),
            fake()->numberBetween(5, 26),
            fake()->numberBetween(1, 99_999),
        );
    }
}
