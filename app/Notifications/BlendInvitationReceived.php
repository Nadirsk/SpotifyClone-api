<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Blend;
use App\Models\User;
use App\Notifications\Concerns\RendersInAppPayload;
use Illuminate\Notifications\Notification;

/**
 * "Karan invited you to a Blend." The one inbox row that carries a token
 * instead of just an id — `href` routes straight to the pre-login-safe
 * accept/decline page, same shape as `PlaylistCollaboratorJoined`'s
 * playlist link but pointed at an invitation rather than at the entity itself,
 * since the recipient is not a member yet and cannot open `/blend/{id}` directly.
 */
final class BlendInvitationReceived extends Notification
{
    use RendersInAppPayload;

    public function __construct(
        private readonly Blend $blend,
        private readonly User $inviter,
        private readonly string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload(
            category: 'blend',
            title: "{$this->inviter->name} invited you to a Blend",
            body: 'Combine your music taste and discover new music together.',
            href: "/blend/invite/{$this->token}",
            image: $this->inviter->avatar,
            meta: [
                'blend_id' => $this->blend->id,
                'inviter_id' => $this->inviter->id,
            ],
        );
    }
}
