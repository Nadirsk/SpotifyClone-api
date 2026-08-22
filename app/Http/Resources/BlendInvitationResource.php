<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BlendInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The pre-login "you've been invited to a Blend" preview
 * (GET /blends/invitations/{token}) — mirrors `PlaylistInvitationResource`,
 * thin for the same reason: reachable before the recipient has accepted or
 * even signed in, so no tracklist and no match score travel with it.
 *
 * `invited_user` is included (playlist invitations have no equivalent,
 * because they are not addressed to anyone in particular) so the page can
 * say "this invite is for {name}" before the visitor signs in as the wrong
 * account and gets a 403 from `POST /blends/invitations/{token}/accept`.
 *
 * @mixin BlendInvitation
 */
final class BlendInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'blend' => [
                'id' => $this->blend->id,
                'title' => $this->blend->title,
            ],
            'invited_by' => [
                'name' => $this->whenLoaded('inviter', fn (): string => $this->inviter->name),
            ],
            'invited_user' => [
                'name' => $this->whenLoaded('invitedUser', fn (): string => $this->invitedUser->name),
            ],
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
