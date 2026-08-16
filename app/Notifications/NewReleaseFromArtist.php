<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Album;
use App\Models\Artist;
use App\Notifications\Concerns\RendersInAppPayload;
use Illuminate\Notifications\Notification;

/**
 * "Arijit Singh released Dhurandhar."
 *
 * The payoff for following an artist, and the reason the follow button promises
 * "We'll let you know when they release something new" on the home page's
 * Following surface. Fanned out by `NotifyFollowersOfRelease` after a sync run
 * discovers an album the catalog had not seen before.
 */
final class NewReleaseFromArtist extends Notification
{
    use RendersInAppPayload;

    public function __construct(
        private readonly Artist $artist,
        private readonly Album $album,
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
            category: 'release',
            title: "{$this->artist->name} released {$this->album->title}",
            body: $this->album->total_tracks === 1
                ? 'A new single is out now.'
                : "{$this->album->total_tracks} new tracks are out now.",
            href: "/album/{$this->album->id}",
            image: $this->album->cover_image,
            meta: [
                'artist_id' => $this->artist->id,
                'album_id' => $this->album->id,
            ],
        );
    }
}
