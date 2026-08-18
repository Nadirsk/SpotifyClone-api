<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PlaylistInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The pre-login "you've been invited" preview (GET /playlists/invitations/
 * {token}) — reachable by anyone holding the link, before they have joined or
 * even signed in. Deliberately thin: no tracklist, no visibility, nothing
 * that would leak a private playlist's contents to someone who has not
 * accepted yet.
 *
 * @mixin PlaylistInvitation
 */
final class PlaylistInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'playlist' => [
                'id' => $this->playlist->id,
                'title' => $this->playlist->title,
                'cover_image' => $this->playlist->cover_image,
                'tracks_count' => $this->playlist->tracks_count,
            ],
            'invited_by' => [
                'name' => $this->whenLoaded('inviter', fn (): string => $this->inviter->name),
            ],
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
