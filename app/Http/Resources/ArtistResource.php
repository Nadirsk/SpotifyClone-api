<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Artist
 */
final class ArtistResource extends JsonResource
{
    /**
     * `trending_score` and `last_synced_at` are deliberately absent: they are
     * ranking/sync internals, not part of 05_API_SPECIFICATION §7.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'bio' => $this->bio,
            'image' => $this->image,
            'country' => $this->country,
            'popularity' => $this->popularity,

            /*
             | Counts only appear where the artist is the primary entity and the
             | service asked for them. Nested inside a song or album they would
             | cost an extra aggregate per response for data nobody renders.
             */
            'albums_count' => $this->whenCounted('albums'),
            'songs_count' => $this->whenCounted('songs'),
        ];
    }
}
