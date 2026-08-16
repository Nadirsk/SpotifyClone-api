<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Playlist
 */
final class PlaylistResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'cover_image' => $this->cover_image,
            'visibility' => $this->visibility->value,
            'is_collaborative' => (bool) $this->is_collaborative,
            /*
             | Whether the caller may add/remove songs as a collaborator —
             | never true for the owner (they don't need it) or for a guest.
             * Uses Playlist::isCollaborator(), which is safe whether or not
             | `collaborators` was eager-loaded.
             */
            'is_collaborator' => $this->isCollaborator($request->user()),
            'tracks_count' => $this->tracks_count,
            'total_duration' => $this->total_duration,
            'created_at' => $this->created_at?->toIso8601String(),
            /*
             | Reduced to id and name on purpose. A public or unlisted playlist
             | is readable by strangers, so the owner's email must never travel
             | with it.
             */
            'owner' => $this->whenLoaded('owner', fn (): array => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'tracks' => SongResource::collection($this->whenLoaded('songs')),
        ];
    }
}
