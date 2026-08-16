<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Playlist;
use App\Models\User;
use App\Notifications\Concerns\RendersInAppPayload;
use Illuminate\Notifications\Notification;

/**
 * "Priya joined Road Trip." Sent to the playlist's owner when an invite link is
 * accepted — the one moment in the collaboration flow the owner is not present
 * for, and therefore the one that needs an inbox entry.
 */
final class PlaylistCollaboratorJoined extends Notification
{
    use RendersInAppPayload;

    public function __construct(
        private readonly Playlist $playlist,
        private readonly User $collaborator,
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
            category: 'collaboration',
            title: "{$this->collaborator->name} joined {$this->playlist->title}",
            body: 'They can now add and remove tracks.',
            href: "/playlist/{$this->playlist->id}",
            image: $this->collaborator->avatar,
            meta: [
                'playlist_id' => $this->playlist->id,
                'collaborator_id' => $this->collaborator->id,
            ],
        );
    }
}
