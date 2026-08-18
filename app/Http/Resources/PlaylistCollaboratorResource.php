<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlaylistCollaborator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlaylistCollaborator
 */
final class PlaylistCollaboratorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // The collaborator's user id — DELETE /playlists/{id}/collaborators/{userId} takes this, not the row id.
            'id' => $this->user_id,
            'name' => $this->whenLoaded('user', fn (): string => $this->user->name),
            'avatar' => $this->whenLoaded('user', fn (): ?string => $this->user->avatar),
            'joined_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
