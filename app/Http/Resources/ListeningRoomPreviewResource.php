<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ListeningRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What somebody holding an invite link may see *before* they join.
 *
 * Enough to answer "is this the room my friend meant, and what are they
 * listening to": the host's name, how many people are in it, and the track
 * currently playing.
 *
 * Deliberately narrower than the spec's suggested preview, which listed the
 * member names. A room code is six characters, so it is guessable at scale in a
 * way a UUID is not, and a preview that names everyone present turns that into a
 * way to enumerate who is listening with whom. The count conveys "there are
 * three people in here" without naming them; the names arrive on joining, which
 * is the point at which the room knows about you too.
 *
 * The queue is absent for the same reason, and the playback *position* because
 * nothing before joining needs it.
 *
 * @mixin ListeningRoom
 */
final class ListeningRoomPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'room_code' => $this->room_code,
            'is_live' => $this->isLive(),
            'host_name' => $this->whenLoaded('host', fn (): ?string => $this->host?->name),
            // Counted by the caller with withCount('members') — see the
            // controller. The whenCounted convention keeps this null rather than
            // throwing if a caller forgets.
            'member_count' => $this->whenCounted('members'),
            'is_playing' => $this->is_playing,
            'current_song' => $this->whenLoaded('currentSong', fn (): ?array => $this->currentSong === null ? null : [
                'id' => $this->currentSong->id,
                'title' => $this->currentSong->title,
                'artist' => $this->currentSong->relationLoaded('artist')
                    ? $this->currentSong->artist?->name
                    : null,
                'artwork_url' => $this->currentSong->relationLoaded('album')
                    ? $this->currentSong->album?->cover_image
                    : null,
            ]),
        ];
    }
}
