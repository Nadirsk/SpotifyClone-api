<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\History;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin History
 */
final class HistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'played_at' => $this->played_at?->toIso8601String(),
            'ms_played' => $this->ms_played,
            // user_id is intentionally absent: history is only ever returned to
            // its own owner, so echoing it back adds nothing.
            'song' => new SongResource($this->whenLoaded('song')),
        ];
    }
}
