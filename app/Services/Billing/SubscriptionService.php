<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\AudioQuality;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Exceptions\DomainException;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Which plan a listener is on, and everything that follows from it.
 *
 * This is the *only* place in the app allowed to decide whether a user is
 * entitled to something. Controllers, the player and the download endpoint all
 * ask `can()`; none of them test the plan name themselves. That is what makes
 * adding a tier a config change (see `config/plans.php`) rather than a search
 * for `=== 'platinum'`.
 *
 * ## Checkout is simulated
 *
 * `subscribe()` writes a real `subscriptions` row with a real price and a real
 * period end, and every gate downstream honours it — but no money moves and no
 * gateway is called. `payment_reference` is a locally-generated `sim_…` id.
 * Swapping in a real processor means replacing the body of `subscribe()` and
 * adding a webhook that sets the same columns; nothing else in the app has to
 * know the difference. The `sim_` prefix is deliberately greppable so a real
 * charge can never be confused with a simulated one in the table.
 */
final class SubscriptionService
{
    /** Every plan sold today bills monthly. */
    private const PERIOD_MONTHS = 1;

    public function __construct(
        private readonly PlanCatalog $plans,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reading the current state
    |--------------------------------------------------------------------------
    */

    /** The newest subscription row, entitling or not. Null if they never bought one. */
    public function currentFor(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->newestFirst()
            ->first();
    }

    /**
     * The plan whose entitlements apply right now.
     *
     * Falls back to `Free` both when no row exists and when the newest one has
     * lapsed — see `SubscriptionPlan::Free`'s doc for why free is an absence
     * rather than a record.
     */
    public function planFor(User $user): SubscriptionPlan
    {
        $subscription = $this->currentFor($user);

        return $subscription?->isEntitled() === true
            ? $subscription->plan
            : SubscriptionPlan::Free;
    }

    /** @return array<string, mixed> */
    public function entitlementsFor(User $user): array
    {
        return $this->plans->entitlements($this->planFor($user));
    }

    /** The one gate. Everything premium-shaped in this app routes through here. */
    public function can(User $user, string $capability): bool
    {
        return $this->plans->entitles($this->planFor($user), $capability);
    }

    /** @throws DomainException 402 when the plan does not cover the capability. */
    public function authorize(User $user, string $capability): void
    {
        if (! $this->can($user, $capability)) {
            throw DomainException::premiumRequired($capability);
        }
    }

    /**
     * The quality this listener actually gets: what they asked for in their
     * profile, clamped to what their plan allows.
     *
     * The clamp happens here rather than being written back to the column, so a
     * lapsed subscription downgrades the *stream* without destroying the
     * *preference* — resubscribing restores it untouched. See the column's
     * migration.
     */
    public function effectiveQualityFor(User $user, ?AudioQuality $requested = null): AudioQuality
    {
        $preferred = $requested
            ?? ($user->audio_quality instanceof AudioQuality
                ? $user->audio_quality
                : AudioQuality::from((string) ($user->audio_quality ?? AudioQuality::Normal->value)));

        return $preferred->clampTo($this->plans->maxQuality($this->planFor($user)));
    }

    /**
     * How many devices this listener may stay signed in on at once, or `null`
     * for uncapped.
     *
     * Resolved through `planFor()` like every other ceiling, so a lapsed
     * subscription drops back to the Free tier's (uncapped) allowance rather
     * than stranding someone at a paid plan's limit they no longer pay for.
     * Enforced by `DeviceSessionService`.
     */
    public function maxSessionsFor(User $user): ?int
    {
        return $this->plans->maxSessions($this->planFor($user));
    }

    /**
     * Whether this account has ever held a paid subscription.
     *
     * The two-months-introductory pricing on the plans page is only offered to
     * accounts where this is false — which is exactly why `subscriptions` keeps
     * history instead of mutating one row.
     */
    public function hasEverSubscribed(User $user): bool
    {
        return Subscription::query()->where('user_id', $user->id)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Mutations
    |--------------------------------------------------------------------------
    */

    /**
     * Buy a plan.
     *
     * Any still-entitling subscription is expired first, so a user is never
     * entitled through two rows at once and `currentFor()`'s "newest wins" rule
     * stays unambiguous. An upgrade is therefore a cancel-and-replace, which is
     * the correct shape for a simulated checkout — a real processor would
     * prorate instead, and that logic belongs with the processor.
     *
     * @throws DomainException
     */
    public function subscribe(User $user, SubscriptionPlan $plan, string $currency): Subscription
    {
        if (! $plan->isPaid()) {
            throw DomainException::planNotPurchasable();
        }

        $current = $this->currentFor($user);

        if ($current?->isEntitled() === true && $current->plan === $plan) {
            throw DomainException::alreadySubscribed($plan->label());
        }

        if ($current?->isEntitled() === true) {
            $this->expire($current);
        }

        $now = Carbon::now();

        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'plan' => $plan,
            'status' => SubscriptionStatus::Active,
            'currency' => $currency,
            'amount_minor' => $this->plans->priceFor($plan, $currency),
            'started_at' => $now,
            'current_period_end' => $now->copy()->addMonths(self::PERIOD_MONTHS),
            'payment_reference' => 'sim_'.Str::lower((string) Str::ulid()),
        ]);

        $user->notify(new SubscriptionChanged($subscription, SubscriptionChanged::EVENT_STARTED));

        return $subscription;
    }

    /**
     * Stop the subscription renewing.
     *
     * Entitlements deliberately survive to `current_period_end` — the listener
     * paid for that period. `Subscription::isEntitled()` is what enforces it.
     *
     * @throws DomainException
     */
    public function cancel(User $user): Subscription
    {
        $current = $this->currentFor($user);

        if ($current === null || ! $current->isEntitled()) {
            throw DomainException::noActiveSubscription();
        }

        $current->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => Carbon::now(),
        ]);

        $user->notify(new SubscriptionChanged($current, SubscriptionChanged::EVENT_CANCELLED));

        return $current->refresh();
    }

    /**
     * Mark every subscription whose period has run out as expired.
     *
     * Purely housekeeping — `isEntitled()` already treats a past
     * `current_period_end` as lapsed, so nothing is granted incorrectly in the
     * window before this runs. Its value is keeping the status column honest
     * for reporting and for the `status`-indexed queries.
     *
     * @return int How many rows were expired.
     */
    public function expireLapsed(?Carbon $at = null): int
    {
        $at ??= Carbon::now();

        return Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Cancelled])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $at)
            ->update(['status' => SubscriptionStatus::Expired]);
    }

    private function expire(Subscription $subscription): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Expired,
            'current_period_end' => Carbon::now(),
        ]);
    }
}
