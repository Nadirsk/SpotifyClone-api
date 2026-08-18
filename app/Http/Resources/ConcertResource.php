<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Concert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Concert
 */
final class ConcertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'date' => $this->date?->toDateString(),
            'date_label' => $this->date_label,
            'event_count' => $this->event_count,
            'genres' => $this->genres ?? [],
            'vendors' => $this->vendors ?? [],
            'image' => $this->image,
            'venue' => $this->whenLoaded(
                'venue',
                fn (): VenueResource => VenueResource::make($this->venue),
            ),
        ];
    }
}
