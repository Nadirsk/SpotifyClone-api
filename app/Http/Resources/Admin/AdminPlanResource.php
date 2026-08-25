<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin panel's own view of a plan row — raw minor-unit prices and the
 * full entitlement map, unlike the public `GET /plans` shape which formats
 * prices for display and never exposes edit-time fields.
 *
 * @mixin Plan
 */
final class AdminPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'plan' => $this->plan,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'accounts' => $this->accounts,
            'max_sessions' => $this->max_sessions,
            'reference_price_inr' => $this->reference_price_inr,
            'reference_price_usd' => $this->reference_price_usd,
            'entitlements' => $this->entitlements,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
