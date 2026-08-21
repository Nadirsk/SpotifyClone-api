<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ListeningRoom;
use App\Models\Song;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * `ListeningRoom::positionMsAt()` — the arithmetic every synchronised listener
 * depends on.
 *
 * Pure unit test against unpersisted models: the whole method is a function of
 * four columns, and pinning it without a database is what makes the edge cases
 * (a clock running backwards, a room left playing overnight) cheap to state.
 */
final class ListeningRoomPositionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_paused_room_stays_exactly_where_it_was_left(): void
    {
        $room = $this->room(is_playing: false, positionMs: 102_500, measuredAgoSeconds: 600);

        // Ten minutes have passed and it has not moved, because it is paused.
        $this->assertSame(102_500, $room->positionMsAt(Carbon::now()));
    }

    public function test_a_playing_room_advances_with_the_wall_clock(): void
    {
        $room = $this->room(is_playing: true, positionMs: 30_000, measuredAgoSeconds: 10);

        $this->assertSame(40_000, $room->positionMsAt(Carbon::now()));
    }

    public function test_milliseconds_are_not_rounded_away(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00.000'));

        $room = new ListeningRoom;
        $room->forceFill([
            'position_ms' => 95_420,
            'is_playing' => true,
            'position_at' => Carbon::parse('2026-08-21 11:59:59.750'),
        ]);

        // 250ms of elapsed time is below the threshold at which clients bother
        // correcting, which is precisely why it must not be lost here: a
        // truncating implementation would report 95_420 and a rounding one
        // 96_420, and both are wrong by more than the tolerance.
        $this->assertSame(95_670, $room->positionMsAt(Carbon::now()));
    }

    /**
     * A room whose `position_at` is in the future — which happens when the
     * writing process's clock is a few milliseconds ahead of the reading one.
     * Seeking to a negative time is variously clamped, ignored or thrown on
     * depending on the browser, so it must never be produced.
     */
    public function test_a_position_is_never_negative(): void
    {
        $room = $this->room(is_playing: true, positionMs: 0, measuredAgoSeconds: -5);

        $this->assertSame(0, $room->positionMsAt(Carbon::now()));
    }

    /**
     * A room left playing long past the end of its track. Handing out a position
     * hours past the end makes a joining listener seek beyond the audio, get
     * `ended` immediately, and sit in silence — indistinguishable from the
     * feature being broken.
     */
    public function test_a_position_is_clamped_to_the_length_of_the_loaded_song(): void
    {
        $room = $this->room(is_playing: true, positionMs: 0, measuredAgoSeconds: 7200);

        $song = new Song;
        $song->forceFill(['duration' => 180]);
        $room->setRelation('currentSong', $song);

        $this->assertSame(180_000, $room->positionMsAt(Carbon::now()));
    }

    /**
     * With no song loaded there is nothing to clamp against, and this must not
     * reach for the relation to find out — lazy loading throws outside
     * production, and a position is legitimately asked for in contexts that
     * never needed the song itself.
     */
    public function test_an_unloaded_song_relation_is_not_touched(): void
    {
        $room = $this->room(is_playing: true, positionMs: 0, measuredAgoSeconds: 7200);

        $this->assertSame(7_200_000, $room->positionMsAt(Carbon::now()));
        $this->assertFalse($room->relationLoaded('currentSong'));
    }

    private function room(bool $is_playing, int $positionMs, int $measuredAgoSeconds): ListeningRoom
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00.000'));

        $room = new ListeningRoom;

        $room->forceFill([
            'position_ms' => $positionMs,
            'is_playing' => $is_playing,
            'position_at' => Carbon::now()->subSeconds($measuredAgoSeconds),
        ]);

        return $room;
    }
}
