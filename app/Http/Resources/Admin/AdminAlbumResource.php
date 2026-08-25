<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Album
 */
final class AdminAlbumResource extends JsonResource
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
            'description' => $this->description,
            'cover_image' => $this->cover_image,
            'release_date' => $this->release_date?->toDateString(),
            'total_tracks' => $this->total_tracks,
            'popularity' => $this->popularity,
            'is_explicit' => (bool) $this->is_explicit,
            'artist' => $this->whenLoaded('artist', fn (): array => [
                'id' => $this->artist->id,
                'name' => $this->artist->name,
            ]),
            'language' => $this->whenLoaded('language', fn (): ?array => $this->language === null ? null : [
                'id' => $this->language->id,
                'name' => $this->language->name,
            ]),
            'songs_count' => $this->whenCounted('songs'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
