<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class HistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_records_a_play_and_it_appears_in_the_index(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/history', ['song_id' => $song->id]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.song.id', $song->id);

        $this->assertDatabaseHas('history', ['user_id' => $user->id, 'song_id' => $song->id]);

        $index = $this->getJson('/api/v1/history');
        $index->assertOk();
        $index->assertJsonCount(1, 'data');
        $index->assertJsonPath('data.0.song.id', $song->id);
    }

    public function test_store_requires_authentication(): void
    {
        $song = Song::factory()->create();

        $this->postJson('/api/v1/history', ['song_id' => $song->id])->assertStatus(401);
    }

    public function test_immediate_repeat_play_of_the_same_song_is_deduped(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/history', ['song_id' => $song->id])->assertCreated();

        $response = $this->postJson('/api/v1/history', ['song_id' => $song->id]);

        // Inside the dedupe window: 200, not 201, and no second row.
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data', null);

        $this->assertDatabaseCount('history', 1);
    }

    public function test_repeat_play_is_not_deduped_once_the_dedupe_window_is_zero(): void
    {
        config(['music.history.dedupe_window_minutes' => 0]);

        $user = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/history', ['song_id' => $song->id])->assertCreated();

        // Force a real elapsed second: the dedupe query is inclusive
        // (`played_at >= now() - window`), so a 0-minute window can only be
        // proven "not deduped" once enough wall-clock time has actually
        // passed that the previous row's timestamp no longer satisfies it.
        $this->travel(1)->seconds();

        $response = $this->postJson('/api/v1/history', ['song_id' => $song->id]);

        $response->assertCreated();
        $this->assertDatabaseCount('history', 2);
    }

    public function test_index_only_lists_the_authenticated_users_history(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $songA = Song::factory()->create();
        $songB = Song::factory()->create();

        Sanctum::actingAs($userA);
        $this->postJson('/api/v1/history', ['song_id' => $songA->id])->assertCreated();

        Sanctum::actingAs($userB);
        $this->postJson('/api/v1/history', ['song_id' => $songB->id])->assertCreated();

        $response = $this->getJson('/api/v1/history');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.song.id', $songB->id);
    }

    public function test_destroy_clears_only_the_authenticated_users_history(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $songA = Song::factory()->create();
        $songB = Song::factory()->create();

        Sanctum::actingAs($userA);
        $this->postJson('/api/v1/history', ['song_id' => $songA->id])->assertCreated();

        Sanctum::actingAs($userB);
        $this->postJson('/api/v1/history', ['song_id' => $songB->id])->assertCreated();

        Sanctum::actingAs($userA);
        $response = $this->deleteJson('/api/v1/history');

        $response->assertNoContent();

        $this->assertDatabaseMissing('history', ['user_id' => $userA->id]);
        $this->assertDatabaseHas('history', ['user_id' => $userB->id, 'song_id' => $songB->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/history')->assertStatus(401);
    }
}
