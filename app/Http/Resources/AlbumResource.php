<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Album
 */
final class AlbumResource extends JsonResource
{
    /**
     * `tracks` is exposed under that name while the underlying relation is
     * `songs` — 05_API_SPECIFICATION §8 calls them tracks.
     *
     * Nothing here loads `songs.album`, which would make SongResource nest an
     * AlbumResource that nests its tracks again.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'cover_image' => $this->cover_image,
            'release_date' => $this->release_date?->toDateString(),
            'total_tracks' => $this->total_tracks,
            /*
             | How many of this album's tracks the catalog actually holds, when
             | the caller counted them.
             |
             | Deliberately alongside `total_tracks` rather than replacing it:
             | that field is what the *provider* said the release contains, and
             | this one is what we have. They disagree constantly — a partially
             | synced album reports ten and holds three — and both answers are
             | worth having. A client deciding which of several same-titled
             | albums to lead with needs this one; a client showing "1 of 12
             | tracks available" needs both.
             */
            'songs_count' => $this->whenCounted('songs'),
            'popularity' => $this->popularity,

            'artist' => $this->whenLoaded(
                'artist',
                fn (): ArtistResource => ArtistResource::make($this->artist),
            ),
            'language' => $this->whenLoaded(
                'language',
                fn (): LanguageResource => LanguageResource::make($this->language),
            ),
            'tracks' => $this->whenLoaded(
                'songs',
                fn (): AnonymousResourceCollection => SongResource::collection($this->songs),
            ),
        ];
    }
}
