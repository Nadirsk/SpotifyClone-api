<?php

declare(strict_types=1);

namespace App\Events\Listening;

use App\Models\ListeningRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The room's queue changed — a track was added, removed, or the order was
 * rewritten.
 *
 * ## Why this carries no tracks
 *
 * The obvious payload is the new queue. It is also the wrong one, for the same
 * entitlement reason {@see RoomPlaybackUpdated} carries no song: a serialised
 * song holds a `preview_url` clamped to the plan of whoever triggered the
 * broadcast, so a shared payload would leak the host's audio tier to every
 * listener in the room.
 *
 * So this is a notification, and members refetch `GET /listening-rooms/{code}`
 * with their own token — which is also what gives each of them a queue with
 * their own entitlements resolved. That is one request per queue edit, on an
 * action a human performs by hand; it is not polling, and it is not on the
 * playback path, which is the one that has to be fast.
 *
 * `size` is here so a client can render "12 songs" immediately and detect a
 * refetch that came back stale, without waiting for the round trip.
 */
final class RoomQueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        private readonly ListeningRoom $room,
        /** 'added' | 'removed' | 'replaced' */
        private readonly string $reason,
        private readonly int $size,
    ) {}

    /** @return list<PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel($this->room->channelName())];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'room_code' => $this->room->room_code,
            'size' => $this->size,
            'server_time_ms' => (int) now()->getPreciseTimestamp(3),
        ];
    }
}
