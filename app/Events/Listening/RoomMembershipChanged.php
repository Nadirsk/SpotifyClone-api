<?php

declare(strict_types=1);

namespace App\Events\Listening;

use App\Models\ListeningRoom;
use App\Models\ListeningRoomMember;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/**
 * Someone joined, someone left, or the host changed.
 *
 * Unlike the playback and queue events, this one *does* carry its data: a member
 * is a name and an avatar, with no entitlement attached to either, so there is
 * nothing that has to be resolved per recipient. Members are also the one thing
 * a room UI shows constantly, and making every arrival cost a refetch would put
 * a request on screen for each person walking in.
 *
 * ## Why this exists at all, given the channel is a presence channel
 *
 * Reverb already tells clients who is subscribed, and that answers "who is
 * connected". It cannot answer "who is a member and what is their role": presence
 * membership is a socket, so it drops the moment a listener's laptop sleeps and
 * comes back as a *join*, and it knows nothing about the host handover that
 * happens when the host is the one who left. Presence is the liveness signal;
 * this is the membership record. The frontend shows both — a member list from
 * here, an online dot from presence.
 */
final class RoomMembershipChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  Collection<int, ListeningRoomMember>  $members  Active members, with `user` loaded.
     */
    public function __construct(
        private readonly ListeningRoom $room,
        /** 'joined' | 'left' | 'host_changed' */
        private readonly string $reason,
        private readonly Collection $members,
    ) {}

    /** @return list<PresenceChannel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel($this->room->channelName())];
    }

    public function broadcastAs(): string
    {
        return 'members.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'room_code' => $this->room->room_code,
            'host_user_id' => $this->room->host_user_id,
            'members' => $this->members
                ->map(fn (ListeningRoomMember $member): array => [
                    'id' => $member->user_id,
                    /*
                     | A member row whose user relation was not loaded would lazy
                     | load here — inside a broadcast, where the resulting
                     | exception surfaces as a failed event rather than a failed
                     | request. The repository loads it; this coalesces rather
                     | than trusting that, because the cost of being wrong is a
                     | room that silently stops updating its member list.
                     */
                    'name' => $member->relationLoaded('user') ? $member->user?->name : null,
                    'avatar' => $member->relationLoaded('user') ? $member->user?->avatar : null,
                    'role' => $member->role->value,
                    'is_host' => $member->user_id === $this->room->host_user_id,
                    'joined_at' => $member->joined_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'server_time_ms' => (int) now()->getPreciseTimestamp(3),
        ];
    }
}
