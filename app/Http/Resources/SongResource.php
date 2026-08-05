<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Song;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Song
 */
final class SongResource extends JsonResource
{
    /**
     * Raw foreign keys (artist_id, album_id, genre_id, language_id) are never
     * emitted — clients navigate through the nested objects. Provider mappings
     * are never emitted at all: exposing a provider's own id would leak the
     * upstream schema and breach its terms (CONVENTIONS "Non-negotiables").
     *
     * Every relationship is read through `whenLoaded`, so a caller that forgot
     * to eager-load gets a smaller payload rather than a lazy-loading violation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            // Null for singles and for providers that omit it.
            'track_number' => $this->track_number,
            'duration' => $this->duration,
            'isrc' => $this->isrc,
            'release_date' => $this->release_date?->toDateString(),
            'popularity' => $this->popularity,
            'preview_url' => $this->preview_url,
            'external_url' => $this->external_url,

            /*
             | The closure form of whenLoaded is required rather than
             | `ArtistResource::make($this->whenLoaded('artist'))`: it yields
             | null for a loaded-but-null relation (a song with no album) and
             | MissingValue only when the relation was never loaded.
             */
            'artist' => $this->whenLoaded(
                'artist',
                fn (): ArtistResource => ArtistResource::make($this->artist),
            ),
            'album' => $this->whenLoaded(
                'album',
                fn (): AlbumResource => AlbumResource::make($this->album),
            ),

            // Genre and language are flattened here; the song payload only ever
            // needs a label, and the full resources belong to their own lists.
            'genre' => $this->whenLoaded('genre', fn (): array => [
                'id' => $this->genre->id,
                'name' => $this->genre->name,
            ]),
            'language' => $this->whenLoaded('language', fn (): array => [
                'id' => $this->language->id,
                'name' => $this->language->name,
            ]),
        ];
    }
}
