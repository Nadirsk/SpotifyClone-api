<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * Read (plus one narrow write) side of subscriptions, for the admin panel.
 *
 * `subscriptions` is a purchase-history table, not a mutable per-user
 * record ({@see Subscription}'s own docblock) — plan, amount and
 * payment_reference reflect what was actually charged and are never
 * admin-editable here. The one action this exposes is a manual status
 * override (e.g. a support-requested cancellation), which is why there is
 * no create/full-update, only {@see updateStatus()}.
 */
final class AdminSubscriptionService
{
    /**
     * @return LengthAwarePaginator<int, Subscription>
     */
    public function paginate(int $page, int $limit, ?string $search, ?string $status): LengthAwarePaginator
    {
        $builder = Subscription::query()->with('user')->orderByDesc('created_at');

        if ($search !== null && $search !== '') {
            $builder->whereHas('user', function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($status !== null && SubscriptionStatus::tryFrom($status) !== null) {
            $builder->where('status', $status);
        }

        /** @var LengthAwarePaginator<int, Subscription> */
        return $builder->paginate(perPage: $limit, page: $page);
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $id): Subscription
    {
        /** @var Subscription */
        return Subscription::query()->with('user')->findOrFail($id);
    }

    /**
     * Cancelling stamps `cancelled_at` if it was not already set — the
     * listener still keeps their entitlement through `current_period_end`
     * either way, same as a self-service cancellation.
     */
    public function updateStatus(Subscription $subscription, string $status): Subscription
    {
        $newStatus = SubscriptionStatus::from($status);

        $subscription->status = $newStatus;

        if ($newStatus === SubscriptionStatus::Cancelled && $subscription->cancelled_at === null) {
            $subscription->cancelled_at = Carbon::now();
        }

        $subscription->save();

        return $subscription;
    }
}
