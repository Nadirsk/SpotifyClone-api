<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ListeningRoom;
use App\Models\ListeningRoomMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A listening room as one of its members sees it: the authoritative playback
 * state, the queue, and who else is here.
 *
 * This is also the resync payload. A client that lost its connection asks for
 * this and gets everything it needs to stop guessing, which is why the playback
 * block and the queue are in the same response rather than in two.
 *
 * @mixin ListeningRoom
 */
final class ListeningRoomResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;

        return [
            'id' => $this->id,
            'room_code' => $this->room_code,
            'is_live' => $this->isLive(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'host' => $this->whenLoaded('host', fn (): ?array => $this->host === null ? null : [
                'id' => $this->host->id,
                'name' => $this->host->name,
                'avatar' => $this->host->avatar,
            ]),

            /*
             | Who the caller is *in this room*, answered by the server.
             |
             | The client could work this out by comparing ids, and the room UI
             | does exactly that for its own rendering. It is still stated here,
             | because "am I the host" decides whether this client is allowed to
             | broadcast playback, and the answer to that question has to come
             | from the same place that enforces it. A client left to infer it
             | from a stale member list can decide it is the host of a room it
             | was handed out of, and spend the rest of the session having its
             | writes rejected.
             */
            'viewer' => [
                'is_host' => $this->isHost($viewer),
                'role' => $this->viewerRole($viewer),
            ],

            /*
             | The playback measurement, exactly as stored.
             |
             | Deliberately *not* an "effective position" computed here. That
             | number would already be stale by the network delay when it arrived,
             | and offering it invites a client to seek to it instead of doing the
             | clock arithmetic — which is the one thing on the client side that
             | cannot be skipped. What is provided instead is both halves of the
             | measurement and the server's own clock, which is enough to compute
             | it correctly at the moment of use.
             */
            'playback' => [
                'song_id' => $this->current_song_id,
                'position_ms' => $this->position_ms,
                'position_at_ms' => $this->position_at !== null
                    ? (int) $this->position_at->getPreciseTimestamp(3)
                    : null,
                'is_playing' => $this->is_playing,
                'playback_version' => $this->playback_version,
            ],

            'members' => $this->whenLoaded(
                'members',
                fn (): AnonymousResourceCollection => ListeningRoomMemberResource::collection($this->members),
            ),

            'queue' => $this->whenLoaded(
                'queue',
                fn (): AnonymousResourceCollection => ListeningRoomQueueItemResource::collection($this->queue),
            ),

            /*
             | The server's clock at the instant this response was built.
             |
             | Every client's estimate of the offset between its clock and this one
             | is built from this field and the round-trip it arrived in. Without
             | it, a listener whose laptop clock is a minute fast computes a
             | position a minute past where the room actually is, and there is
             | nothing about the audio itself that would ever reveal why.
             */
            'server_time_ms' => (int) now()->getPreciseTimestamp(3),
        ];
    }

    /**
     * The caller's role, or null when they are not a member.
     *
     * Read off the loaded members rather than queried: this resource is
     * serialised for a payload that already has them, and a query here would run
     * once per room in any list of rooms.
     */
    private function viewerRole(?User $viewer): ?string
    {
        if ($viewer === null || ! $this->resource->relationLoaded('members')) {
            return null;
        }

        $member = $this->members
            ->first(fn (ListeningRoomMember $member): bool => $member->user_id === $viewer->getKey());

        return $member?->role->value;
    }
}
