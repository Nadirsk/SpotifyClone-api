<?php

declare(strict_types=1);

namespace Tests\Feature\Recommendations;

use App\Models\Artist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/recommendations')->assertStatus(401);
    }

    public function test_returns_empty_when_the_user_has_no_favorites(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/recommendations');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_recommends_songs_related_to_a_favorite(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        $favorited = Song::factory()->for($artist)->create(['popularity' => 50]);
        $related = Song::factory()->for($artist)->create(['popularity' => 80]);
        // A song from an unrelated artist should never surface.
        Song::factory()->create(['popularity' => 99]);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/favorites/{$favorited->id}")->assertCreated();

        $response = $this->getJson('/api/v1/recommendations');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($related->id));
        $this->assertFalse($ids->contains($favorited->id));
    }
}
