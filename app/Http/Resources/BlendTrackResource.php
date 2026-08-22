<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BlendMember;
use App\Models\Song;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A `Song` inside a Blend's tracklist, plus why it is there and who it is
 * credited to — the reference UI (a flat, Spotify-style list) renders
 * `added_by` as a per-row avatar and ignores `blend_reason`, but both are
 * exposed since neither costs anything to send (12_SCOPE_OF_WORK §9 only
 * asks that the raw `blend_songs.score` itself stay internal).
 *
 * @mixin Song
 */
final class BlendTrackResource extends JsonResource
{
    /**
     * @param  Song  $resource
     * @param  Collection<string, BlendMember>|null  $membersByUserId  Keyed by
     *         `user_id`, so `attributed_user_id` resolves to a name/avatar
     *         without a query — the caller already has the Blend's members loaded.
     */
    public function __construct($resource, private readonly ?Collection $membersByUserId = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attributedUserId = $this->pivot?->attributed_user_id;
        $attributedMember = $attributedUserId !== null ? $this->membersByUserId?->get($attributedUserId) : null;

        return [
            ...(new SongResource($this->resource))->resolve($request),
            'blend_reason' => $this->pivot?->reason?->value,
            'added_by' => $attributedMember !== null ? [
                'id' => $attributedMember->user->id,
                'name' => $attributedMember->user->name,
                'avatar' => $attributedMember->user->avatar,
            ] : null,
        ];
    }
}
