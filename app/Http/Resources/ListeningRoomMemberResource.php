<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ListeningRoomMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListeningRoomMember
 */
final class ListeningRoomMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // The user id, not the membership row id: it is what the member list
            // keys on and what `host_user_id` is compared against.
            'id' => $this->user_id,
            'name' => $this->whenLoaded('user', fn (): ?string => $this->user?->name),
            'avatar' => $this->whenLoaded('user', fn (): ?string => $this->user?->avatar),
            'role' => $this->role->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            /*
             | When this member was last seen making a request. Not a presence
             | signal — presence comes over the WebSocket channel, which knows
             | about sockets rather than about requests — but it is what lets the
             | UI grey out somebody whose tab has been shut for an hour without
             | waiting for the prune to close the room.
             */
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
