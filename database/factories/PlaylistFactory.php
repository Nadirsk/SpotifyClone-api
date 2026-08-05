<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlaylistVisibility;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Playlist>
 */
class PlaylistFactory extends Factory
{
    /**
     * `tracks_count` and `total_duration` are deliberately absent: they are
     * denormalized counters, are not fillable, and must only ever be written
     * from the tracks that actually exist.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->words(3, true));

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => fake()->sentence(),
            'cover_image' => 'https://picsum.photos/seed/'.Str::slug($title).'/600/600',
            // Matches the column default: a new playlist is private until shared.
            'visibility' => PlaylistVisibility::Private,
        ];
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => PlaylistVisibility::Public,
        ]);
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => PlaylistVisibility::Private,
        ]);
    }

    public function unlisted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => PlaylistVisibility::Unlisted,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
        ]);
    }
}
