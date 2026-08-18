<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as anyone browsing their profile sees them — deliberately NOT
 * `UserResource`, which includes `email` and is only ever returned for the
 * token's own account (`GET /profile`). Exposing another user's email on a
 * public profile page would be a real leak, not a style choice.
 *
 * `followers_count`/`following_count` follow the same `whenCounted()`
 * convention as `ArtistResource`'s `albums_count`/`songs_count` — the
 * controller must eager-load them with `->loadCount(['followers', 'following'])`
 * (or the repository's query must, for a paginated list).
 *
 * @mixin User
 */
final class PublicUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'followers_count' => $this->whenCounted('followers'),
            'following_count' => $this->whenCounted('following'),
        ];
    }
}
