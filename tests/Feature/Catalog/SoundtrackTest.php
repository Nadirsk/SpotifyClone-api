<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Album;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SoundtrackTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_groups_songs_by_film_and_counts_them(): void
    {
        Song::factory()->create(['title' => 'Tum Hi Ho (From "Aashiqui 2")', 'film_title' => 'Aashiqui 2']);
        Song::factory()->create(['title' => 'Sunn Raha Hai (From "Aashiqui 2")', 'film_title' => 'Aashiqui 2']);
        Song::factory()->create(['title' => 'Gehra Hua (From "Dhurandhar")', 'film_title' => 'Dhurandhar']);
        // Not film music — must not appear as a film of its own.
        Song::factory()->create(['title' => 'Some Indie Single', 'film_title' => null]);

        $response = $this->getJson('/api/v1/soundtracks')->assertOk();

        $this->assertSame(2, $response->json('pagination.total'));

        $films = collect($response->json('data'))->keyBy('film');

        $this->assertSame(2, $films['Aashiqui 2']['track_count']);
        $this->assertSame(1, $films['Dhurandhar']['track_count']);
    }

    public function test_index_is_paginated(): void
    {
        foreach (range(1, 5) as $index) {
            Song::factory()->create(['film_title' => "Film {$index}"]);
        }

        $response = $this->getJson('/api/v1/soundtracks?limit=2&page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('pagination.total'));
        $this->assertSame(3, $response->json('pagination.last_page'));
    }

    public function test_index_uses_the_most_popular_tracks_album_cover(): void
    {
        $album = Album::factory()->create(['cover_image' => 'https://c.saavncdn.com/cover.jpg']);

        Song::factory()->for($album)->create(['film_title' => 'Aashiqui 2', 'popularity' => 90]);
        Song::factory()->create(['film_title' => 'Aashiqui 2', 'popularity' => 10]);

        $this->getJson('/api/v1/soundtracks')
            ->assertOk()
            ->assertJsonPath('data.0.cover_image', 'https://c.saavncdn.com/cover.jpg');
    }

    public function test_show_returns_the_films_tracks(): void
    {
        Song::factory()->create(['title' => 'Tum Hi Ho', 'film_title' => 'Aashiqui 2', 'popularity' => 90]);
        Song::factory()->create(['title' => 'Sunn Raha Hai', 'film_title' => 'Aashiqui 2', 'popularity' => 50]);
        Song::factory()->create(['film_title' => 'Dhurandhar']);

        $response = $this->getJson('/api/v1/soundtracks/'.rawurlencode('Aashiqui 2'))->assertOk();

        $this->assertSame('Aashiqui 2', $response->json('data.film'));
        $this->assertSame(2, $response->json('data.track_count'));
        // Ordered by popularity, so the film's best-known track leads.
        $this->assertSame('Tum Hi Ho', $response->json('data.songs.0.title'));
    }

    public function test_show_handles_a_film_name_with_spaces_and_punctuation(): void
    {
        Song::factory()->create(['film_title' => 'Rock On!! 2']);

        $this->getJson('/api/v1/soundtracks/'.rawurlencode('Rock On!! 2'))
            ->assertOk()
            ->assertJsonPath('data.film', 'Rock On!! 2');
    }

    public function test_show_is_404_for_an_unknown_film(): void
    {
        $this->getJson('/api/v1/soundtracks/'.rawurlencode('Not A Real Film'))->assertStatus(404);
    }

    public function test_the_hub_is_public(): void
    {
        Song::factory()->create(['film_title' => 'Aashiqui 2']);

        $this->getJson('/api/v1/soundtracks')->assertOk();
        $this->getJson('/api/v1/soundtracks/'.rawurlencode('Aashiqui 2'))->assertOk();
    }
}
