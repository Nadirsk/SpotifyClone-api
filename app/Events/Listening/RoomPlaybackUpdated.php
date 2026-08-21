<?php

declare(strict_types=1);

namespace App\Events\Listening;

use App\Enums\PlaybackReason;
use App\Models\ListeningRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The room's authoritative playback state changed.
 *
 * One event for play, pause, seek and every kind of track change, because every
 * one of them is the same sentence — "this song, at this position, playing or
 * not, as of this instant" — and participants apply all of them through one
 * code path. Splitting it per verb would duplicate the payload five times and
 * give five handlers the chance to drift apart on the clock arithmetic, which is
 * the only part that is actually hard.
 *
 * ## Why `ShouldBroadcastNow`
 *
 * Not `ShouldBroadcast`. Queued broadcasts go onto the `database` queue in this
 * app (`QUEUE_CONNECTION=database`), which means a play would reach the other
 * listeners whenever a worker next polls — a second or more later on a good day,
 * and *never* on a machine where nobody has started `queue:work`. A feature whose
 * entire promise is "you are hearing the same thing at the same time" cannot be
 * built on a delivery path that is allowed to be late. This sends inline, on the
 * host's request thread, and costs one HTTP call to Reverb.
 *
 * ## Why no song metadata
 *
 * Participants already hold the room queue, so a song id is enough to name the
 * track. Embedding the song here instead would put a `preview_url` in the
 * payload — and that URL is clamped to the *plan of whoever triggered the
 * broadcast* (see AudioAccess), so a Premium host pressing play would hand every
 * free listener in the room a premium-quality URL. The same reasoning is why the
 * catalog's own listings carry the free variant: shared payloads cannot carry
 * per-listener entitlements. Each client resolves its own stream at play time
 * against `GET /songs/{id}/stream` with its own token.
 *
 * A client that receives an id it cannot find in its copy of the queue refetches
 * the room rather than guessing — see the frontend's room sync layer.
 */
final class RoomPlaybackUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        private readonly ListeningRoom $room,
        private readonly PlaybackReason $reason,
    ) {}

    /** @return list<PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel($this->room->channelName())];
    }

    public function broadcastAs(): string
    {
        return 'playback.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $now = now();

        return [
            'reason' => $this->reason->value,
            'room_code' => $this->room->room_code,
            'song_id' => $this->room->current_song_id,
            /*
             | The stored measurement, not the extrapolated one. Sending
             | `positionMsAt(now())` would be a position that was true when this
             | payload was built and is already stale by the time it is parsed;
             | the pair below lets every recipient extrapolate against its own
             | corrected clock instead, which is the whole point of carrying
             | `position_at_ms`.
             */
            'position_ms' => $this->room->position_ms,
            'is_playing' => $this->room->is_playing,
            'position_at_ms' => $this->room->position_at !== null
                ? (int) $this->room->position_at->getPreciseTimestamp(3)
                : null,
            'playback_version' => $this->room->playback_version,
            /*
             | Sent on every event so a client can keep its clock offset fresh
             | without asking for it. One sample from a broadcast is noisier than
             | the round-trip estimate the client makes on join (it includes the
             | one-way delay, which is unmeasurable from a single timestamp), so
             | this is a sanity check on the offset rather than a replacement for
             | it — see the frontend's room-clock module.
             */
            'server_time_ms' => (int) $now->getPreciseTimestamp(3),
        ];
    }
}
