<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Language
 */
final class LanguageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            /* Derived by TaxonomyService — see GenreResource for why these are
               omitted rather than zeroed when absent. */
            'song_count' => $this->whenNotNull($this->song_count),
            'cover_image' => $this->whenNotNull($this->cover_image),
        ];
    }
}
