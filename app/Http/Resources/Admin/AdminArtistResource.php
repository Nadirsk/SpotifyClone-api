<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Artist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin panel's own view of an artist — unlike the public
 * ArtistResource, this exposes the sync/profile fields an admin actually
 * edits (verification, socials, dominant type/language).
 *
 * @mixin Artist
 */
final class AdminArtistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'bio' => $this->bio,
            'image' => $this->image,
            'country' => $this->country,
            'popularity' => $this->popularity,
            'follower_count' => $this->follower_count,
            'is_verified' => (bool) $this->is_verified,
            'dominant_type' => $this->dominant_type,
            'dominant_language' => $this->dominant_language,
            'birth_date' => $this->birth_date,
            'facebook_url' => $this->facebook_url,
            'twitter_url' => $this->twitter_url,
            'wiki_url' => $this->wiki_url,
            'albums_count' => $this->whenCounted('albums'),
            'songs_count' => $this->whenCounted('songs'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
