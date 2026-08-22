<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Blend;
use App\Models\Song;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Blend
 */
final class BlendResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'title_is_default' => (bool) $this->title_is_default,
            /** Null until the first generation has run — see the blends migration's own doc. */
            'match_score' => $this->match_score,
            'tracks_count' => $this->tracks_count,
            'total_duration' => $this->total_duration,
            'is_creator' => $viewer instanceof User && $this->isCreator($viewer),
            /*
             | Whether the caller may act on this Blend at all — same role
             | `PlaylistResource.is_collaborator` plays, but true for the
             | creator too: a Blend has no "owner doesn't need this flag"
             | asymmetry the way a playlist's collaborator flag does.
             */
            'is_member' => $this->isMember($viewer instanceof User ? $viewer : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_generated_at' => $this->last_generated_at?->toIso8601String(),
            'creator' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'members' => BlendMemberResource::collection($this->whenLoaded('members')),
            /*
             | Not BlendTrackResource::collection() — each row needs the
             | Blend's own members (for "added by"), which a plain
             | ::collection() call has no way to hand to every item.
             */
            'tracks' => $this->whenLoaded('songs', function () use ($request): array {
                $membersByUserId = $this->members->keyBy('user_id');

                return $this->songs
                    ->map(fn (Song $song) => (new BlendTrackResource($song, $membersByUserId))->resolve($request))
                    ->all();
            }),
        ];
    }
}
