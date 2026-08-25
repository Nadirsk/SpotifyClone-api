<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Song;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin panel's own view of a song — unlike the public SongResource,
 * `preview_url` is the raw stored column (no AudioAccess/subscription
 * resolution: an admin is managing the catalog, not listening to it).
 *
 * @mixin Song
 */
final class AdminSongResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'track_number' => $this->track_number,
            'duration' => $this->duration,
            'isrc' => $this->isrc,
            'release_date' => $this->release_date?->toDateString(),
            'popularity' => $this->popularity,
            'trending_score' => $this->trending_score,
            'play_count' => $this->play_count,
            'preview_url' => $this->preview_url,
            'external_url' => $this->external_url,
            'label' => $this->label,
            'copyright' => $this->copyright,
            'is_explicit' => (bool) $this->is_explicit,
            'has_lyrics' => (bool) $this->has_lyrics,
            'artist' => $this->whenLoaded('artist', fn (): array => [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
            ]),
            'album' => $this->whenLoaded('album', fn (): ?array => $this->album === null ? null : [
                'id' => $this->album->id,
                'title' => $this->album->title,
            ]),
            'genre' => $this->whenLoaded('genre', fn (): ?array => $this->genre === null ? null : [
                'id' => $this->genre->id,
                'name' => $this->genre->name,
            ]),
            'language' => $this->whenLoaded('language', fn (): ?array => $this->language === null ? null : [
                'id' => $this->language->id,
                'name' => $this->language->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
