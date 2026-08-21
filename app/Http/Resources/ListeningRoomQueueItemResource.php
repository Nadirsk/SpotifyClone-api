<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ListeningRoomQueueItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One track in a room queue.
 *
 * The song is serialised with the ordinary {@see SongResource}, which is what
 * makes this endpoint's queue interchangeable with every other track list in the
 * API: the frontend runs it through the same `songToTrack` adapter it uses for
 * playlists and favorites, and gets Playables with no room-specific code at all.
 *
 * It also means `preview_url` is clamped to *this caller's* plan, because
 * SongResource resolves it through AudioAccess against `$request->user()`. That
 * is the reason the queue is fetched per listener rather than pushed down the
 * broadcast — see RoomQueueUpdated.
 *
 * @mixin ListeningRoomQueueItem
 */
final class ListeningRoomQueueItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // The queue row's own id — DELETE /listening-rooms/{code}/queue/{item}
            // takes this, not the song id, because a queue may hold the same song
            // more than once and removing "that one" has to be unambiguous.
            'id' => $this->id,
            'queue_position' => $this->queue_position,
            // The display name, not the raw `added_by` user id — nothing in this
            // app reads the column itself over the API (only the repository
            // writes it), so there is no contract to preserve, and a room's own
            // "Added by" column needs a name the same way a playlist's does.
            'added_by' => $this->whenLoaded('addedBy', fn (): ?string => $this->addedBy?->name),
            'song' => $this->whenLoaded('song', fn (): SongResource => SongResource::make($this->song)),
        ];
    }
}
