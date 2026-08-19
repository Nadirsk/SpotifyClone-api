<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /*
    |--------------------------------------------------------------------------
    | Guest plays
    |--------------------------------------------------------------------------
    |
    | Recording a play is the one write on this controller that is open, and
    | that is deliberate: every popularity figure in the product is derived from
    | this table, so counting only signed-in plays would make each of them a
    | chart of subscribers presented as a chart of listening. Reading and
    | clearing history stay authenticated — those are a person's own feed, and a
    | session id is not an authentication factor.
    */

    public function test_a_signed_out_listener_can_record_a_play(): void
    {
        $song = Song::factory()->create();

        $this->postJson('/api/v1/history', [
            'song_id' => $song->id,
            'session_id' => 'guest-session-abc',
        ])->assertCreated();

        $this->assertDatabaseHas('history', [
            'song_id' => $song->id,
            'user_id' => null,
            'session_id' => 'guest-session-abc',
        ]);
    }

    public function test_a_guest_repeat_play_is_deduped_on_the_session_id(): void
    {
        $song = Song::factory()->create();

        $this->postJson('/api/v1/history', ['song_id' => $song->id, 'session_id' => 'same-browser'])
            ->assertCreated();

        // Same browser, same song, inside the dedupe window: counted once.
        $this->postJson('/api/v1/history', ['song_id' => $song->id, 'session_id' => 'same-browser'])
            ->assertOk()
            ->assertJsonPath('data', null);

        // A different browser is a different listener.
        $this->postJson('/api/v1/history', ['song_id' => $song->id, 'session_id' => 'other-browser'])
            ->assertCreated();

        $this->assertSame(2, DB::table('history')->where('song_id', $song->id)->count());
    }

    public function test_a_play_shorter_than_the_listen_threshold_is_not_counted(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create(['duration' => 240]);
        $before = $song->play_count;

        Sanctum::actingAs($user);

        // Four seconds of a four-minute track is a skip, not a play.
        $this->postJson('/api/v1/history', ['song_id' => $song->id, 'ms_played' => 4_000])
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('history', ['song_id' => $song->id]);
        // The factory seeds a play_count, so the assertion is that it did not
        // move — not that it is zero.
        $this->assertSame($before, $song->fresh()->play_count);
    }

    public function test_a_play_past_the_listen_threshold_is_counted(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create(['duration' => 240]);
        $before = $song->play_count;

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/history', ['song_id' => $song->id, 'ms_played' => 31_000])
            ->assertCreated();

        $this->assertDatabaseHas('history', ['song_id' => $song->id, 'user_id' => $user->id]);
        $this->assertSame($before + 1, $song->fresh()->play_count);
    }

    public function test_a_short_track_played_past_the_halfway_mark_is_counted(): void
    {
        $user = User::factory()->create();
        // A 20-second sting can never reach the 30-second absolute threshold, so
        // the fractional rule is the only one that can ever count it.
        $song = Song::factory()->create(['duration' => 20]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/history', ['song_id' => $song->id, 'ms_played' => 12_000])
            ->assertCreated();

        $this->assertDatabaseHas('history', ['song_id' => $song->id]);
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
