<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Subscription;
use App\Notifications\Concerns\RendersInAppPayload;
use Illuminate\Notifications\Notification;

/**
 * "Your Premium Standard plan is active" / "…will end on 14 September".
 *
 * One class for both events rather than two, because the inbox renders them
 * identically and the only difference is the copy.
 */
final class SubscriptionChanged extends Notification
{
    use RendersInAppPayload;

    public const EVENT_STARTED = 'started';

    public const EVENT_CANCELLED = 'cancelled';

    public function __construct(
        private readonly Subscription $subscription,
        private readonly string $event,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $plan = $this->subscription->plan;
        $ends = $this->subscription->current_period_end?->isoFormat('D MMMM YYYY');

        return $this->payload(
            category: 'subscription',
            title: $this->event === self::EVENT_STARTED
                ? "{$plan->label()} is active"
                : "{$plan->label()} will not renew",
            body: $this->event === self::EVENT_STARTED
                ? 'Downloads, offline listening and very high audio quality are unlocked.'
                : ($ends === null
                    ? 'Your plan has been cancelled.'
                    : "You keep Premium until {$ends}."),
            href: '/premium',
            meta: [
                'plan' => $plan->value,
                'event' => $this->event,
                'current_period_end' => $this->subscription->current_period_end?->toIso8601String(),
            ],
        );
    }
}
