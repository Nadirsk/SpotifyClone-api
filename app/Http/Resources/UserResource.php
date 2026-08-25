<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a user.
 *
 * Whitelisted field by field on purpose: `password`, `remember_token`,
 * `email_verified_at` and the soft-delete timestamp must never reach a client,
 * and an explicit list cannot start leaking them when a column is added.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role?->value,
            'avatar' => $this->avatar,
            'country' => $this->country,
            'language' => $this->language,
            /*
             | The listener's *preferred* tier, not the one they will actually
             | be served — that depends on their plan and is reported by
             | `GET /subscription` as `effective_audio_quality`. Two fields
             | rather than one because the settings screen has to render the
             | preference even while the plan is clamping it.
             */
            'audio_quality' => $this->audio_quality?->value,
            'offline_enabled' => (bool) $this->offline_enabled,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
