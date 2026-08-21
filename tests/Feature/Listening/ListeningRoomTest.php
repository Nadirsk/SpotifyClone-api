<?php

declare(strict_types=1);

namespace Tests\Feature\Listening;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Events\Listening\RoomMembershipChanged;
use App\Events\Listening\RoomPlaybackUpdated;
use App\Events\Listening\RoomQueueUpdated;
use App\Models\ListeningRoom;
use App\Models\ListeningRoomMember;
use App\Models\Song;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Listening\ListeningRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Listen Together — rooms, membership, host succession, the authoritative
 * playback state and the queue.
 *
 * The numbered comments map to the acceptance scenarios in the feature brief, so
 * a scenario that stops being covered is visible rather than merely absent.
 *
 * Broadcasts are asserted through `Event::fake` rather than by standing up
 * Reverb: what matters at this layer is that the right event carrying the right
 * authoritative state was dispatched to the right channel. That the WebSocket
 * itself delivers is verified by the browser suite, which runs two real
 * sessions against a real Reverb process.
 */
final class ListeningRoomTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A user on the plan `store()`/`join()` actually require.
     *
     * Listen Together is Platinum-exclusive (`config/plans.php` →
     * `listen_together`), for the host and every guest, so any test that goes
     * through the HTTP layer for either endpoint needs one of these rather than
     * a bare `User::factory()->create()`. Tests that only call
     * {@see ListeningRoomService} directly do not — that class assumes its
     * caller is already allowed, which is exactly what the gate's own test
     * below pins.
     */
    private function platinumUser(): User
    {
        $user = User::factory()->create();

        Subscription::query()->create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Platinum,
            'status' => SubscriptionStatus::Active,
            'currency' => 'INR',
            'amount_minor' => 23920,
            'started_at' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $user;
    }

    // -----------------------------------------------------------------
    // The plan gate
    // -----------------------------------------------------------------

    public function test_opening_a_room_requires_platinum(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/listening-rooms')->assertStatus(402);
    }

    public function test_joining_a_room_requires_platinum_even_for_a_free_guest(): void
    {
        $room = ListeningRoom::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/join")->assertStatus(402);
    }

    // -----------------------------------------------------------------
    // Creating a room
    // -----------------------------------------------------------------

    public function test_a_listener_can_open_a_room_and_becomes_its_host(): void
    {
        $host = $this->platinumUser();
        $songs = Song::factory()->count(3)->create();

        Sanctum::actingAs($host);

        $response = $this->postJson('/api/v1/listening-rooms', [
            'song_ids' => $songs->pluck('id')->all(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.viewer.is_host', true);
        $response->assertJsonPath('data.viewer.role', 'host');
        $response->assertJsonPath('data.is_live', true);
        $response->assertJsonCount(3, 'data.queue');

        $code = $response->json('data.room_code');

        $this->assertIsString($code);
        $this->assertSame(
            (int) config('music.listening.code_length'),
            strlen($code),
            'The room code should be exactly the configured length.',
        );
        $this->assertMatchesRegularExpression(
            '/^['.preg_quote((string) config('music.listening.code_alphabet'), '/').']+$/',
            $code,
            'The room code should only use the unambiguous alphabet.',
        );

        $this->assertDatabaseHas('listening_rooms', [
            'room_code' => $code,
            'host_user_id' => $host->id,
            'is_playing' => false,
        ]);

        $this->assertDatabaseHas('listening_room_members', [
            'user_id' => $host->id,
            'role' => 'host',
            'left_at' => null,
        ]);
    }

    public function test_opening_a_room_requires_authentication(): void
    {
        $this->postJson('/api/v1/listening-rooms')->assertStatus(401);
    }

    /**
     * The host is normally mid-song when they press "Listen Together", and the
     * room has to open where they already are rather than at zero.
     */
    public function test_a_room_can_be_opened_at_the_position_the_host_is_already_at(): void
    {
        $host = $this->platinumUser();
        $song = Song::factory()->create();

        Sanctum::actingAs($host);

        $response = $this->postJson('/api/v1/listening-rooms', [
            'song_ids' => [$song->id],
            'current_song_id' => $song->id,
            'position_ms' => 95_420,
            'is_playing' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.playback.song_id', $song->id);
        $response->assertJsonPath('data.playback.position_ms', 95_420);
        $response->assertJsonPath('data.playback.is_playing', true);
        $this->assertNotNull($response->json('data.playback.position_at_ms'));
        $this->assertSame(1, $response->json('data.playback.playback_version'));
    }

    public function test_naming_a_song_without_a_position_is_rejected(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($host);

        $this->postJson('/api/v1/listening-rooms', ['current_song_id' => $song->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['position_ms', 'is_playing']);
    }

    public function test_a_queue_containing_an_unknown_song_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/listening-rooms', [
            'song_ids' => [Song::factory()->create()->id, (string) Str::uuid()],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['song_ids']);
    }

    // -----------------------------------------------------------------
    // Joining — scenario 1
    // -----------------------------------------------------------------

    public function test_a_second_listener_can_join_and_both_are_in_the_room(): void
    {
        Event::fake([RoomMembershipChanged::class]);

        $host = User::factory()->create();
        $guest = $this->platinumUser();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($guest);

        $response = $this->postJson("/api/v1/listening-rooms/{$room->room_code}/join");

        $response->assertOk();
        $response->assertJsonPath('data.viewer.is_host', false);
        $response->assertJsonPath('data.viewer.role', 'participant');
        $response->assertJsonCount(2, 'data.members');

        Event::assertDispatched(
            RoomMembershipChanged::class,
            fn (RoomMembershipChanged $event): bool => $this->payloadOf($event)['reason'] === 'joined'
                && count($this->payloadOf($event)['members']) === 2,
        );
    }

    public function test_a_room_code_is_matched_case_insensitively(): void
    {
        $room = ListeningRoom::factory()->create();

        Sanctum::actingAs($this->platinumUser());

        $this->postJson('/api/v1/listening-rooms/'.strtolower($room->room_code).'/join')->assertOk();
    }

    public function test_joining_twice_does_not_duplicate_the_member_or_announce_them_again(): void
    {
        Event::fake([RoomMembershipChanged::class]);

        $guest = $this->platinumUser();
        $room = ListeningRoom::factory()->create();

        Sanctum::actingAs($guest);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/join")->assertOk();
        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/join")->assertOk();

        $this->assertSame(1, ListeningRoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $guest->id)
            ->count());

        Event::assertDispatchedTimes(RoomMembershipChanged::class, 1);
    }

    public function test_an_unknown_room_code_is_a_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/listening-rooms/ZZZZZZ/join')->assertStatus(404);
    }

    /** A room that has finished is a different answer from a code that never existed. */
    public function test_an_ended_room_is_a_410(): void
    {
        $room = ListeningRoom::factory()->ended()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/join")->assertStatus(410);
    }

    public function test_a_full_room_refuses_new_listeners(): void
    {
        config()->set('music.listening.max_members', 2);

        $room = ListeningRoom::factory()->create();
        $service = app(ListeningRoomService::class);

        $service->join($room, User::factory()->create());
        $service->join($room, User::factory()->create());

        Sanctum::actingAs($this->platinumUser());

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/join")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // -----------------------------------------------------------------
    // Reading the room — the resync path
    // -----------------------------------------------------------------

    public function test_a_member_reads_the_full_room_state(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->playing($song, 30_000)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->replaceQueue($room, $host, [$song->id]);

        Sanctum::actingAs($host);

        $response = $this->getJson("/api/v1/listening-rooms/{$room->room_code}");

        $response->assertOk();
        $response->assertJsonPath('data.playback.song_id', $song->id);
        $response->assertJsonPath('data.playback.is_playing', true);
        $response->assertJsonPath('data.queue.0.song.id', $song->id);
        $response->assertJsonPath('data.host.id', $host->id);
        $this->assertIsInt($response->json('data.server_time_ms'));
        $this->assertNotNull($response->json('data.queue.0.song.title'));
    }

    public function test_a_non_member_cannot_read_a_room(): void
    {
        $host = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/listening-rooms/{$room->room_code}")->assertStatus(403);
    }

    /**
     * Scenario 7 — somebody joining an active room must not start at zero.
     *
     * The server does not hand out an extrapolated position (see
     * ListeningRoomResource); it hands out the measurement and its timestamp, so
     * what this pins is that the pair is present and describes a room that has
     * been playing for a while.
     */
    public function test_a_room_that_has_been_playing_reports_a_position_that_has_moved(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create(['duration' => 240]);

        $room = ListeningRoom::factory()
            ->hostedBy($host)
            ->playing($song, 30_000, now()->subSeconds(10)->format('Y-m-d H:i:s.v'))
            ->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $response = $this->getJson("/api/v1/listening-rooms/{$room->room_code}");

        $response->assertOk();
        $response->assertJsonPath('data.playback.position_ms', 30_000);

        // Ten seconds of wall clock have passed since the measurement, so the
        // room is really ~40s in. That is the client's arithmetic, and this is
        // the input it needs to do it.
        $room->refresh()->loadMissing('currentSong');

        $this->assertGreaterThanOrEqual(39_000, $room->positionMsAt(now()));
        $this->assertLessThanOrEqual(41_000, $room->positionMsAt(now()));
    }

    // -----------------------------------------------------------------
    // Playback — scenarios 2, 3, 4, 5, 10, 11
    // -----------------------------------------------------------------

    public function test_the_host_can_drive_playback_and_the_room_is_told(): void
    {
        Event::fake([RoomPlaybackUpdated::class]);

        $host = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $response = $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
            'reason' => 'play',
            'song_id' => $song->id,
            'position_ms' => 95_420,
            'is_playing' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.playback.position_ms', 95_420);
        $response->assertJsonPath('data.playback.is_playing', true);
        $response->assertJsonPath('data.playback.playback_version', 1);

        $this->assertDatabaseHas('listening_rooms', [
            'id' => $room->id,
            'current_song_id' => $song->id,
            'position_ms' => 95_420,
            'is_playing' => true,
        ]);

        Event::assertDispatched(RoomPlaybackUpdated::class, function (RoomPlaybackUpdated $event): bool {
            $payload = $this->payloadOf($event);

            return $payload['reason'] === 'play'
                && $payload['position_ms'] === 95_420
                && $payload['is_playing'] === true
                && $payload['playback_version'] === 1
                && is_int($payload['position_at_ms'])
                && is_int($payload['server_time_ms']);
        });
    }

    public function test_pausing_seeking_and_changing_track_all_go_through_the_same_endpoint(): void
    {
        $host = User::factory()->create();
        [$first, $second] = Song::factory()->count(2)->create()->all();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        foreach ([
            ['reason' => 'play', 'song_id' => $first->id, 'position_ms' => 0, 'is_playing' => true],
            ['reason' => 'pause', 'song_id' => $first->id, 'position_ms' => 102_500, 'is_playing' => false],
            ['reason' => 'seek', 'song_id' => $first->id, 'position_ms' => 165_000, 'is_playing' => false],
            ['reason' => 'next', 'song_id' => $second->id, 'position_ms' => 0, 'is_playing' => true],
        ] as $body) {
            $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", $body)->assertOk();
        }

        $this->assertDatabaseHas('listening_rooms', [
            'id' => $room->id,
            'current_song_id' => $second->id,
            'position_ms' => 0,
            'is_playing' => true,
            // Four accepted writes, four bumps.
            'playback_version' => 4,
        ]);
    }

    /**
     * Scenario 11 — a delayed event must never win.
     *
     * The client discards anything older than the highest version it has
     * applied; that only works if the server never reissues or reuses a version,
     * which is what this pins.
     */
    public function test_every_playback_write_gets_a_strictly_higher_version(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $versions = [];

        for ($i = 0; $i < 5; $i++) {
            $versions[] = $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
                'reason' => 'seek',
                'song_id' => $song->id,
                'position_ms' => $i * 1000,
                'is_playing' => true,
            ])->json('data.playback.playback_version');
        }

        $this->assertSame([1, 2, 3, 4, 5], $versions);
    }

    /** Scenario 10 — a participant cannot take the wheel. */
    public function test_a_participant_cannot_drive_playback(): void
    {
        Event::fake([RoomPlaybackUpdated::class]);

        $host = User::factory()->create();
        $guest = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->join($room, $guest);

        Sanctum::actingAs($guest);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
            'reason' => 'play',
            'song_id' => $song->id,
            'position_ms' => 0,
            'is_playing' => true,
        ])->assertStatus(403);

        Event::assertNotDispatched(RoomPlaybackUpdated::class);

        $this->assertDatabaseHas('listening_rooms', [
            'id' => $room->id,
            'playback_version' => 0,
            'is_playing' => false,
        ]);
    }

    public function test_a_stranger_cannot_drive_playback_of_a_room_they_are_not_in(): void
    {
        $room = ListeningRoom::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
            'reason' => 'play',
            'song_id' => Song::factory()->create()->id,
            'position_ms' => 0,
            'is_playing' => true,
        ])->assertStatus(403);
    }

    public function test_playback_validation_rejects_a_missing_song_key_and_a_bad_reason(): void
    {
        $host = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        // `song_id` is `present`, so omitting it is a failure rather than a
        // silent blanking of the room's current track.
        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
            'reason' => 'play',
            'position_ms' => 0,
            'is_playing' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['song_id']);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
            'reason' => 'teleport',
            'song_id' => null,
            'position_ms' => 0,
            'is_playing' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
            'reason' => 'seek',
            'song_id' => null,
            'position_ms' => -5,
            'is_playing' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['position_ms']);
    }

    /**
     * The precision the `datetime(3)` column and the model's `$dateFormat` exist
     * for, asserted across a real write and read.
     *
     * Eloquent formats dates with the connection's `Y-m-d H:i:s` unless told
     * otherwise, so this is the regression that catches the truncation coming
     * back — silently, and only visible as every room being up to a second out.
     */
    public function test_the_measurement_timestamp_keeps_its_milliseconds_through_the_database(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $response = $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
            'reason' => 'play',
            'song_id' => $song->id,
            'position_ms' => 1_000,
            'is_playing' => true,
        ]);

        $stored = ListeningRoom::query()->findOrFail($room->id);
        $milliseconds = (int) $stored->position_at->format('v');

        $this->assertSame(
            (int) $stored->position_at->getPreciseTimestamp(3),
            $response->json('data.playback.position_at_ms'),
            'The API must report the same instant that was stored.',
        );

        /*
         | A truncating write lands on .000 every time. One run in a thousand
         | legitimately does too, so this asserts on the *column*, not on luck:
         | writing a known fraction and reading it back is the real check.
         */
        ListeningRoom::query()->whereKey($room->id)->update([
            'position_at' => now()->startOfSecond()->format('Y-m-d H:i:s').'.750',
        ]);

        $this->assertSame(750, (int) ListeningRoom::query()->findOrFail($room->id)->position_at->format('v'));
        $this->assertGreaterThanOrEqual(0, $milliseconds);
    }

    /**
     * The host dates its own measurement, and the server stores that instant
     * rather than the one the request happened to arrive at.
     *
     * Two distinct bugs live here, and both were real:
     *
     * 1. Stamping arrival biases every extrapolation short by one request's
     *    latency — measured at ~800ms locally, against a 250ms correction
     *    threshold.
     * 2. The claimed instant has to be converted into the application's timezone
     *    before it is stored. Carbon 3 builds timestamps in UTC, this app runs on
     *    Asia/Kolkata, and the column is naive — so the first version of this
     *    stored a time five and a half hours in the past and had participants
     *    seeking hours past the end of a three-minute song.
     */
    public function test_the_host_can_date_its_own_position_measurement(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        /* Half a second ago, as an epoch in milliseconds — what a client sends. */
        $measuredAt = (int) now()->subMilliseconds(500)->getPreciseTimestamp(3);

        $response = $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
            'reason' => 'play',
            'song_id' => $song->id,
            'position_ms' => 30_000,
            'is_playing' => true,
            'position_at_ms' => $measuredAt,
        ]);

        $response->assertOk();

        $stored = $response->json('data.playback.position_at_ms');

        $this->assertIsInt($stored);
        $this->assertLessThan(
            50,
            abs($stored - $measuredAt),
            'The stored instant should be the one the client measured at, to the millisecond.',
        );

        /*
         | The consequence, stated in the terms that actually broke: the room's
         | own extrapolation must land just past the reported position, not hours
         | away from it.
         */
        $room->refresh()->loadMissing('currentSong');

        $this->assertGreaterThanOrEqual(30_400, $room->positionMsAt(now()));
        $this->assertLessThanOrEqual(31_500, $room->positionMsAt(now()));
    }

    /**
     * A timestamp the server cannot believe is ignored in favour of its own
     * clock. Without this, a client with a badly wrong clock — or one being
     * deliberately awkward — could place the room's position anywhere.
     */
    public function test_an_implausible_measurement_time_falls_back_to_the_server_clock(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        foreach ([
            'an hour in the future' => (int) now()->addHour()->getPreciseTimestamp(3),
            'an hour in the past' => (int) now()->subHour()->getPreciseTimestamp(3),
        ] as $label => $claimed) {
            $response = $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", [
                'reason' => 'seek',
                'song_id' => $song->id,
                'position_ms' => 1_000,
                'is_playing' => true,
                'position_at_ms' => $claimed,
            ]);

            $response->assertOk();

            $stored = (int) $response->json('data.playback.position_at_ms');

            $this->assertLessThan(
                5_000,
                abs($stored - (int) now()->getPreciseTimestamp(3)),
                "A measurement {$label} should have been replaced by the server's own clock.",
            );
        }
    }

    // -----------------------------------------------------------------
    // Leaving and host succession — scenario 9
    // -----------------------------------------------------------------

    public function test_when_the_host_leaves_the_longest_present_member_takes_over(): void
    {
        Event::fake([RoomMembershipChanged::class]);

        $host = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->join($room, $first);
        $service->join($room, $second);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/leave")->assertNoContent();

        $room->refresh();

        $this->assertSame($first->id, $room->host_user_id, 'The earliest joiner should inherit the room.');
        $this->assertTrue($room->isLive(), 'The room should survive its host leaving.');

        $this->assertDatabaseHas('listening_room_members', [
            'room_id' => $room->id,
            'user_id' => $first->id,
            'role' => 'host',
        ]);

        Event::assertDispatched(
            RoomMembershipChanged::class,
            fn (RoomMembershipChanged $event): bool => $this->payloadOf($event)['reason'] === 'host_changed'
                && $this->payloadOf($event)['host_user_id'] === $first->id,
        );
    }

    public function test_the_new_host_can_drive_playback_and_the_old_one_cannot(): void
    {
        $host = User::factory()->create();
        $heir = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->join($room, $heir);
        $service->leave($room, $host);

        $body = [
            'reason' => 'play',
            'song_id' => $song->id,
            'position_ms' => 0,
            'is_playing' => true,
        ];

        Sanctum::actingAs($heir);
        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", $body)->assertOk();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/playback", $body)->assertStatus(403);
    }

    public function test_a_room_closes_when_its_last_member_leaves(): void
    {
        $host = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/leave")->assertNoContent();

        $room->refresh();

        $this->assertFalse($room->isLive(), 'An empty room should be closed rather than left open.');
        $this->assertFalse($room->is_playing);
    }

    public function test_a_participant_leaving_does_not_disturb_the_room(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->join($room, $guest);

        Sanctum::actingAs($guest);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/leave")->assertNoContent();

        $room->refresh();

        $this->assertTrue($room->isLive());
        $this->assertSame($host->id, $room->host_user_id);
    }

    /**
     * Leaving is fired by the button and again by the page unloading, so the
     * same departure arrives twice as a matter of course. A client firing a
     * beacon on unload cannot read a response either, so the second call has to
     * be a no-op rather than a rejection.
     */
    public function test_leaving_twice_is_not_an_error(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->join($room, $guest);

        Sanctum::actingAs($guest);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/leave")->assertNoContent();
        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/leave")->assertNoContent();

        $this->assertTrue($room->refresh()->isLive(), 'A double leave must not close a room with people in it.');
    }

    // -----------------------------------------------------------------
    // The queue — scenario 14
    // -----------------------------------------------------------------

    public function test_the_host_replaces_the_queue_and_order_is_preserved(): void
    {
        Event::fake([RoomQueueUpdated::class]);

        $host = User::factory()->create();
        $songs = Song::factory()->count(3)->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $ordered = $songs->pluck('id')->reverse()->values()->all();

        $response = $this->putJson("/api/v1/listening-rooms/{$room->room_code}/queue", [
            'song_ids' => $ordered,
        ]);

        $response->assertOk();
        $response->assertJsonCount(3, 'data.queue');
        $this->assertSame(
            $ordered,
            array_map(
                static fn (array $item): string => $item['song']['id'],
                $response->json('data.queue'),
            ),
            'The queue must come back in the order the host sent it.',
        );

        Event::assertDispatched(
            RoomQueueUpdated::class,
            fn (RoomQueueUpdated $event): bool => $this->payloadOf($event)['reason'] === 'replaced'
                && $this->payloadOf($event)['size'] === 3,
        );
    }

    public function test_clearing_the_queue_is_an_empty_list_not_a_validation_failure(): void
    {
        $host = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->replaceQueue($room, $host, [Song::factory()->create()->id]);

        Sanctum::actingAs($host);

        $this->putJson("/api/v1/listening-rooms/{$room->room_code}/queue", ['song_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.queue');
    }

    public function test_a_song_can_be_added_and_removed_by_the_host(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $added = $this->postJson("/api/v1/listening-rooms/{$room->room_code}/queue", ['song_id' => $song->id]);

        $added->assertCreated();
        $added->assertJsonCount(1, 'data.queue');
        $added->assertJsonPath('data.queue.0.queue_position', 0);

        $itemId = $added->json('data.queue.0.id');

        $this->deleteJson("/api/v1/listening-rooms/{$room->room_code}/queue/{$itemId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.queue');
    }

    public function test_removing_a_song_that_is_not_in_the_queue_is_a_404(): void
    {
        $host = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $this->deleteJson("/api/v1/listening-rooms/{$room->room_code}/queue/".Str::uuid())
            ->assertStatus(404);
    }

    public function test_a_participant_cannot_touch_the_queue(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->join($room, $guest);

        Sanctum::actingAs($guest);

        $this->putJson("/api/v1/listening-rooms/{$room->room_code}/queue", ['song_ids' => []])->assertStatus(403);
        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/queue", [
            'song_id' => Song::factory()->create()->id,
        ])->assertStatus(403);
    }

    public function test_the_queue_is_capped(): void
    {
        config()->set('music.listening.max_queue', 1);

        $host = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->replaceQueue($room, $host, [Song::factory()->create()->id]);

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/listening-rooms/{$room->room_code}/queue", [
            'song_id' => Song::factory()->create()->id,
        ])->assertStatus(422);
    }

    /**
     * A room queue mirrors a player queue, and a player queue legitimately holds
     * the same song twice — an album with a reprise. Collapsing duplicates would
     * silently reorder the host's own queue.
     */
    public function test_the_same_song_may_appear_in_a_queue_twice(): void
    {
        $host = User::factory()->create();
        $song = Song::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        app(ListeningRoomService::class)->join($room, $host);

        Sanctum::actingAs($host);

        $this->putJson("/api/v1/listening-rooms/{$room->room_code}/queue", [
            'song_ids' => [$song->id, $song->id],
        ])->assertOk()->assertJsonCount(2, 'data.queue');
    }

    // -----------------------------------------------------------------
    // The invite preview, and channel authorization
    // -----------------------------------------------------------------

    public function test_a_non_member_can_preview_a_room_without_seeing_who_is_in_it(): void
    {
        $host = User::factory()->create(['name' => 'Asha']);
        $song = Song::factory()->create(['title' => 'Dhurandhar']);
        $room = ListeningRoom::factory()->hostedBy($host)->playing($song, 0)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->join($room, User::factory()->create());

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson("/api/v1/listening-rooms/{$room->room_code}/preview");

        $response->assertOk();
        $response->assertJsonPath('data.host_name', 'Asha');
        $response->assertJsonPath('data.member_count', 2);
        $response->assertJsonPath('data.current_song.title', 'Dhurandhar');

        // The queue and the member identities stay behind the join.
        $response->assertJsonMissingPath('data.queue');
        $response->assertJsonMissingPath('data.members');
    }

    public function test_the_room_channel_admits_members_and_refuses_everyone_else(): void
    {
        $host = User::factory()->create();
        $guest = User::factory()->create();
        $stranger = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->join($room, $guest);

        $hostPresence = $service->channelMembership((string) $room->id, $host);
        $guestPresence = $service->channelMembership((string) $room->id, $guest);

        $this->assertNotNull($hostPresence);
        $this->assertTrue($hostPresence['is_host']);
        $this->assertNotNull($guestPresence);
        $this->assertFalse($guestPresence['is_host']);
        $this->assertSame('participant', $guestPresence['role']);

        $this->assertNull(
            $service->channelMembership((string) $room->id, $stranger),
            'A non-member must not be able to subscribe to a room channel.',
        );

        // Leaving revokes the subscription too, or a departed listener keeps
        // receiving the room after walking out of it.
        $service->leave($room, $guest);

        $this->assertNull($service->channelMembership((string) $room->id, $guest));
    }

    public function test_an_ended_room_admits_nobody_to_its_channel(): void
    {
        $host = User::factory()->create();
        $room = ListeningRoom::factory()->hostedBy($host)->create();

        $service = app(ListeningRoomService::class);
        $service->join($room, $host);
        $service->leave($room, $host);

        $this->assertNull($service->channelMembership((string) $room->id, $host));
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    public function test_idle_rooms_are_closed_by_the_prune_command(): void
    {
        $fresh = ListeningRoom::factory()->create();
        $stale = ListeningRoom::factory()->create();

        // Written past the model so `updated_at` is not refreshed by the write.
        ListeningRoom::query()->whereKey($stale->id)->update([
            'updated_at' => now()->subMinutes((int) config('music.listening.idle_expiry_minutes') + 30),
        ]);

        $this->artisan('listening-rooms:prune')->assertSuccessful();

        $this->assertNull($fresh->refresh()->ended_at, 'An active room must survive the prune.');
        $this->assertNotNull($stale->refresh()->ended_at, 'An abandoned room should be closed.');
    }

    /**
     * The broadcast payload, as the client will receive it.
     *
     * `broadcastWith()` is where the wire format actually lives, so asserting on
     * the event's constructor arguments would pin the wrong thing — a payload
     * that dropped a field would still pass.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(object $event): array
    {
        /** @var array<string, mixed> */
        return $event->broadcastWith();
    }
}
