<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Genre;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Genre
 */
final class GenreResource extends JsonResource
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
            /*
             | Derived by TaxonomyService, not columns. Omitted rather than
             | zeroed when absent: a genre nested on a song has not been
             | counted, and reporting `0` there would read as "no songs".
             */
            'song_count' => $this->whenNotNull($this->song_count),
            'cover_image' => $this->whenNotNull($this->cover_image),
        ];
    }
}
