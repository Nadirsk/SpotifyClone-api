<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Concert;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Concert>
 */
class ConcertFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'venue_id' => Venue::factory(),
            'title' => fake()->words(3, true),
            'date' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'date_label' => null,
            'event_count' => null,
            'genres' => fake()->randomElements(['Pop', 'Rock', 'Electronic', 'Bollywood', 'Indie'], 2),
            'vendors' => [['name' => 'BookMyShow', 'price' => 'from ₹'.fake()->numberBetween(500, 5000)]],
            'image' => null,
        ];
    }
}
