<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Concert;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConcertTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_public_and_lists_all_concerts(): void
    {
        $venue = Venue::factory()->create();
        Concert::factory()->count(3)->create(['venue_id' => $venue->id]);

        $response = $this->getJson('/api/v1/concerts');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_index_filters_by_city(): void
    {
        $mumbai = Venue::factory()->create(['city' => 'Mumbai']);
        $delhi = Venue::factory()->create(['city' => 'Delhi']);

        Concert::factory()->create(['venue_id' => $mumbai->id, 'title' => 'In Mumbai']);
        Concert::factory()->create(['venue_id' => $delhi->id, 'title' => 'In Delhi']);

        $response = $this->getJson('/api/v1/concerts?city=Mumbai');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'In Mumbai');
    }

    public function test_index_filters_by_genre(): void
    {
        $venue = Venue::factory()->create();

        Concert::factory()->create(['venue_id' => $venue->id, 'title' => 'Rock Show', 'genres' => ['Rock', 'Metal']]);
        Concert::factory()->create(['venue_id' => $venue->id, 'title' => 'Pop Show', 'genres' => ['Pop']]);

        $response = $this->getJson('/api/v1/concerts?genre=Rock');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Rock Show');
    }

    public function test_index_filters_by_date_range(): void
    {
        $venue = Venue::factory()->create();

        Concert::factory()->create(['venue_id' => $venue->id, 'title' => 'Early', 'date' => '2026-01-01']);
        Concert::factory()->create(['venue_id' => $venue->id, 'title' => 'Late', 'date' => '2026-12-31']);

        $response = $this->getJson('/api/v1/concerts?from=2026-06-01&to=2026-12-31');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Late');
    }

    public function test_show_returns_a_single_concert_with_its_venue(): void
    {
        $venue = Venue::factory()->create(['name' => 'Test Arena']);
        $concert = Concert::factory()->create(['venue_id' => $venue->id]);

        $response = $this->getJson("/api/v1/concerts/{$concert->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $concert->id);
        $response->assertJsonPath('data.venue.name', 'Test Arena');
    }

    public function test_show_404s_for_an_unknown_id(): void
    {
        $this->getJson('/api/v1/concerts/019fea86-0000-7000-8000-000000000000')
            ->assertStatus(404);
    }

    public function test_venues_index_is_public(): void
    {
        Venue::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/venues');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }
}
