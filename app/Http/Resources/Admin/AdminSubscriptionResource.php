<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin panel's own view of a subscription — {@see \App\Http\Resources\SubscriptionResource}
 * is scoped to the token's own user and so has no reason to say whose row
 * it is; this is the one place that matters.
 *
 * @mixin Subscription
 */
final class AdminSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->plan->value,
            'plan_label' => $this->plan->label(),
            'status' => $this->status->value,
            'entitled' => $this->isEntitled(),
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,
            'started_at' => $this->started_at?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
        ];
    }
}
