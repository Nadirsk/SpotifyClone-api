<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_the_authenticated_users_favorites(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $mySong = Song::factory()->create();
        $otherSong = Song::factory()->create();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/favorites/{$mySong->id}")->assertCreated();

        Sanctum::actingAs($otherUser);
        $this->postJson("/api/v1/favorites/{$otherSong->id}")->assertCreated();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/favorites');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $mySong->id);
        $this->assertArrayHasKey('pagination', $response->json());
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/favorites')->assertStatus(401);
    }

    public function test_store_favorites_a_song(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/favorites/{$song->id}");

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'song_id' => $song->id]);
        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_store_is_idempotent_and_does_not_duplicate_the_row(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/favorites/{$song->id}")->assertCreated();
        $response = $this->postJson("/api/v1/favorites/{$song->id}");

        // Re-favouriting is not an error: the requested end state already holds.
        $response->assertCreated();
        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_destroy_removes_a_favorite(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/favorites/{$song->id}")->assertCreated();

        $response = $this->deleteJson("/api/v1/favorites/{$song->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('favorites', ['user_id' => $user->id, 'song_id' => $song->id]);
    }

    public function test_destroy_is_idempotent_on_a_song_that_was_never_favorited(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/favorites/{$song->id}");

        $response->assertNoContent();
    }

    public function test_destroy_requires_authentication(): void
    {
        $song = Song::factory()->create();

        $this->deleteJson("/api/v1/favorites/{$song->id}")->assertStatus(401);
    }

    public function test_user_b_never_sees_user_as_favorites(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($userA);
        $this->postJson("/api/v1/favorites/{$song->id}")->assertCreated();

        Sanctum::actingAs($userB);
        $response = $this->getJson('/api/v1/favorites');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
