<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BlendInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The creator's own view of one invitation they sent — `POST`/`GET
 * /blends/{id}/invitations` — as opposed to `BlendInvitationResource`, the
 * thin pre-login preview the *recipient*'s link opens to. This one is only
 * ever shown to the creator, so it can safely name who it was sent to and
 * whether they have responded yet.
 *
 * @mixin BlendInvitation
 */
final class BlendInvitationLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'status' => $this->status->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'invited_user' => $this->whenLoaded('invitedUser', fn (): array => [
                'id' => $this->invitedUser->id,
                'name' => $this->invitedUser->name,
                'avatar' => $this->invitedUser->avatar,
            ]),
        ];
    }
}
