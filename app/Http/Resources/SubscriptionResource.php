<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
final class SubscriptionResource extends JsonResource
{
    /**
     * `entitled` is computed rather than derived from `status` by the client:
     * a cancelled subscription still grants Premium until `current_period_end`
     * (see `Subscription::isEntitled()`), and duplicating that rule in the
     * frontend is exactly how the two would come to disagree.
     *
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
        ];
    }
}
