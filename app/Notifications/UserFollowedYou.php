<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\RendersInAppPayload;
use Illuminate\Notifications\Notification;

/** "Priya started following you." */
final class UserFollowedYou extends Notification
{
    use RendersInAppPayload;

    public function __construct(
        private readonly User $follower,
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
            category: 'follow',
            title: "{$this->follower->name} started following you",
            body: 'See what they are listening to.',
            href: "/user/{$this->follower->id}",
            image: $this->follower->avatar,
            meta: ['follower_id' => $this->follower->id],
        );
    }
}
