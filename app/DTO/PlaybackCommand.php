<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\PlaybackReason;

/**
 * A host's intended playback state for their room.
 *
 * Carries a *state*, not a verb: "this song, at this position, playing or not".
 * The alternative — a stream of `play` / `pause` / `seek` commands the server
 * folds into a state it maintains — was rejected because it makes the server's
 * copy a replay of a message log, and a single dropped or reordered message
 * leaves it permanently wrong with nothing to notice that from. A full state on
 * every write is idempotent: applying the same command twice, or applying only
 * the last of five, lands in the same place.
 *
 * `positionMs` is where the host's own audio element actually was when the
 * change happened, not where the server thinks it should have been. The element
 * is the only honest source for that (see the frontend's PlayerAudio), and the
 * difference is exactly the drift this feature exists to remove.
 */
final readonly class PlaybackCommand
{
    public function __construct(
        public PlaybackReason $reason,
        /** Null only for a room that has nothing loaded — a host who cleared the queue. */
        public ?string $songId,
        public int $positionMs,
        public bool $isPlaying,
        /**
         * When `positionMs` was measured, on the *server's* clock, in epoch
         * milliseconds — or null to have the server stamp its own arrival time.
         *
         * ## Why the client is allowed to say when
         *
         * Stamping arrival looks like the safe choice and is quietly wrong. The
         * measurement is taken in the browser, then spends a request getting
         * here: one-way network, plus PHP boot, plus this app's own queries. So
         * the pair that gets stored is a position from *then* with a timestamp
         * from *now*, and every extrapolation from it lands short by exactly
         * that delay.
         *
         * It is not a rounding error. Measured on the development machine —
         * Apache, one request at a time — the host's own audio ran ~800ms ahead
         * of the room's extrapolation of it, which is most of a second of
         * permanent, systematic error in a feature whose correction threshold is
         * 250ms. Every participant faithfully synchronised to a state that was
         * wrong about the host.
         *
         * The client can date its own measurement because it already estimates
         * this clock — see the frontend's room-clock module, whose whole job is
         * that offset. Trusting it is bounded rather than absolute: the service
         * clamps the value into a narrow window around its own clock (see
         * ListeningRoomService::measuredAt), so the worst a host can do by lying
         * is misplace the position of the room they are already driving.
         */
        public ?int $positionAtMs = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  From UpdatePlaybackRequest.
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            PlaybackReason::from((string) $validated['reason']),
            isset($validated['song_id']) ? (string) $validated['song_id'] : null,
            (int) $validated['position_ms'],
            (bool) $validated['is_playing'],
            isset($validated['position_at_ms']) ? (int) $validated['position_at_ms'] : null,
        );
    }
}
