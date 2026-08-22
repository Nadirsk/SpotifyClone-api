<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BlendMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BlendMember
 */
final class BlendMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // The member's user id — what DELETE /blends/{id}/members/{userId} takes.
            'id' => $this->user->id,
            'name' => $this->user->name,
            'avatar' => $this->user->avatar,
            'role' => $this->role->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
        ];
    }
}
