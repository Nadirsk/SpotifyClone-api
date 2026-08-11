<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ArtistFollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_the_authenticated_users_followed_artists(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $myArtist = Artist::factory()->create();
        $otherArtist = Artist::factory()->create();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/artists/{$myArtist->id}/follow")->assertCreated();

        Sanctum::actingAs($otherUser);
        $this->postJson("/api/v1/artists/{$otherArtist->id}/follow")->assertCreated();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/artists/followed');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $myArtist->id);
        $this->assertArrayHasKey('pagination', $response->json());
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/artists/followed')->assertStatus(401);
    }

    public function test_store_follows_an_artist(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/artists/{$artist->id}/follow");

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('artist_follows', ['user_id' => $user->id, 'artist_id' => $artist->id]);
        $this->assertDatabaseCount('artist_follows', 1);
    }

    public function test_store_is_idempotent_and_does_not_duplicate_the_row(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/artists/{$artist->id}/follow")->assertCreated();
        $response = $this->postJson("/api/v1/artists/{$artist->id}/follow");

        // Re-following is not an error: the requested end state already holds.
        $response->assertCreated();
        $this->assertDatabaseCount('artist_follows', 1);
    }

    public function test_destroy_unfollows_an_artist(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/artists/{$artist->id}/follow")->assertCreated();

        $response = $this->deleteJson("/api/v1/artists/{$artist->id}/follow");

        $response->assertNoContent();
        $this->assertDatabaseMissing('artist_follows', ['user_id' => $user->id, 'artist_id' => $artist->id]);
    }

    public function test_destroy_is_idempotent_on_an_artist_that_was_never_followed(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/v1/artists/{$artist->id}/follow");

        $response->assertNoContent();
    }

    public function test_destroy_requires_authentication(): void
    {
        $artist = Artist::factory()->create();

        $this->deleteJson("/api/v1/artists/{$artist->id}/follow")->assertStatus(401);
    }

    public function test_user_b_never_sees_user_as_followed_artists(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $artist = Artist::factory()->create();

        Sanctum::actingAs($userA);
        $this->postJson("/api/v1/artists/{$artist->id}/follow")->assertCreated();

        Sanctum::actingAs($userB);
        $response = $this->getJson('/api/v1/artists/followed');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
