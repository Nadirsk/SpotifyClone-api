<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four tiers a listener can be on.
 *
 * `Free` is not a row in `subscriptions` — it is the absence of an active one,
 * which is why `SubscriptionService::planFor()` returns it as the fallback
 * rather than seeding every new account with a subscription record. That keeps
 * "has the user ever paid" answerable by the table's existence alone.
 *
 * Student is deliberately its own case rather than Standard-with-a-discount:
 * the two differ in eligibility (an accredited institution) even though their
 * entitlements are identical, and collapsing them would make the eligibility
 * re-check at renewal impossible to express.
 */
enum SubscriptionPlan: string
{
    case Free = 'free';
    case Standard = 'standard';
    case Platinum = 'platinum';
    case Student = 'student';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The three that can actually be purchased — `Free` is the absence of a purchase. */
    public static function purchasable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $plan): bool => $plan !== self::Free,
        ));
    }

    public function isPaid(): bool
    {
        return $this !== self::Free;
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Standard => 'Premium Standard',
            self::Platinum => 'Premium Platinum',
            self::Student => 'Premium Student',
        };
    }
}
