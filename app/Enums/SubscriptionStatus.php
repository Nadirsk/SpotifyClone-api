<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a `subscriptions` row.
 *
 * `Cancelled` is not the same as `Expired`: a cancelled subscription keeps its
 * entitlements until `current_period_end` (the listener paid for that period),
 * while an expired one has already passed it. Both are "not renewing"; only one
 * of them still grants Premium. `SubscriptionService::isEntitled()` is the
 * single place that distinction is applied.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
