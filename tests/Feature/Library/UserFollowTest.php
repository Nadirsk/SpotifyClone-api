<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UserFollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_the_public_shape_without_email(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe']);

        $response = $this->getJson("/api/v1/users/{$user->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.name', 'Jane Doe');
        $response->assertJsonPath('data.followers_count', 0);
        $response->assertJsonPath('data.following_count', 0);
        $response->assertJsonMissingPath('data.email');
    }

    public function test_show_is_public_and_requires_no_authentication(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/v1/users/{$user->id}")->assertOk();
    }

    public function test_store_follows_a_user_and_updates_both_counts(): void
    {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        Sanctum::actingAs($follower);
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertCreated();

        $this->assertDatabaseHas('user_follows', [
            'follower_id' => $follower->id,
            'followed_id' => $target->id,
        ]);

        $targetResponse = $this->getJson("/api/v1/users/{$target->id}");
        $targetResponse->assertJsonPath('data.followers_count', 1);

        $followerResponse = $this->getJson("/api/v1/users/{$follower->id}");
        $followerResponse->assertJsonPath('data.following_count', 1);
    }

    public function test_store_is_idempotent_and_does_not_duplicate_the_row(): void
    {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        Sanctum::actingAs($follower);
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertCreated();
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertCreated();

        $this->assertDatabaseCount('user_follows', 1);
    }

    public function test_cannot_follow_yourself(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/v1/users/{$user->id}/follow");

        $response->assertStatus(422);
        $this->assertDatabaseCount('user_follows', 0);
    }

    public function test_store_requires_authentication(): void
    {
        $target = User::factory()->create();

        $this->postJson("/api/v1/users/{$target->id}/follow")->assertStatus(401);
    }

    public function test_destroy_unfollows_a_user(): void
    {
        $follower = User::factory()->create();
        $target = User::factory()->create();

        Sanctum::actingAs($follower);
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertCreated();

        $response = $this->deleteJson("/api/v1/users/{$target->id}/follow");

        $response->assertNoContent();
        $this->assertDatabaseMissing('user_follows', [
            'follower_id' => $follower->id,
            'followed_id' => $target->id,
        ]);
    }

    public function test_followers_lists_who_follows_the_subject(): void
    {
        $target = User::factory()->create();
        $followerA = User::factory()->create(['name' => 'A']);
        $followerB = User::factory()->create(['name' => 'B']);
        $unrelated = User::factory()->create();

        Sanctum::actingAs($followerA);
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertCreated();

        Sanctum::actingAs($followerB);
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertCreated();

        $response = $this->getJson("/api/v1/users/{$target->id}/followers");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $this->assertArrayHasKey('pagination', $response->json());

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($followerA->id, $ids);
        $this->assertContains($followerB->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_following_lists_who_the_subject_follows(): void
    {
        $follower = User::factory()->create();
        $targetA = User::factory()->create();
        $targetB = User::factory()->create();

        Sanctum::actingAs($follower);
        $this->postJson("/api/v1/users/{$targetA->id}/follow")->assertCreated();
        $this->postJson("/api/v1/users/{$targetB->id}/follow")->assertCreated();

        $response = $this->getJson("/api/v1/users/{$follower->id}/following");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }
}
