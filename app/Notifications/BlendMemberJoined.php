<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Blend;
use App\Models\User;
use App\Notifications\Concerns\RendersInAppPayload;
use Illuminate\Notifications\Notification;

/**
 * "Priya joined Aaibuzz + Vishal." Sent to every existing member (creator
 * included) when someone accepts a Blend invitation — mirrors
 * `PlaylistCollaboratorJoined`, but fans out to every member rather than only
 * the owner, since a Blend has no single "owner sees everything" viewpoint
 * once it grows past two people (12_SCOPE_OF_WORK §30).
 */
final class BlendMemberJoined extends Notification
{
    use RendersInAppPayload;

    public function __construct(
        private readonly Blend $blend,
        private readonly User $newMember,
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
            title: "{$this->newMember->name} joined {$this->blend->title}",
            body: 'Your Blend has been updated with their taste.',
            href: "/blend/{$this->blend->id}",
            image: $this->newMember->avatar,
            meta: [
                'blend_id' => $this->blend->id,
                'member_id' => $this->newMember->id,
            ],
        );
    }
}
