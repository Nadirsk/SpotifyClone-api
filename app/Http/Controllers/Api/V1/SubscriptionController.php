<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SubscribeRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\User;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's own subscription, and the simulated checkout that creates one.
 *
 * See `SubscriptionService`'s docblock for what "simulated" means precisely:
 * the entitlement is real and every gate honours it, but no money moves.
 */
final class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanCatalog $plans,
    ) {}

    /**
     * GET /subscription
     *
     * Always 200, never 404: "I am on the free tier" is a valid answer, and
     * making the client treat a 404 as free-tier would mean every caller
     * hand-rolls that mapping. `subscription` is null in that case.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $plan = $this->subscriptions->planFor($user);
        $current = $this->subscriptions->currentFor($user);

        return $this->respondSuccess([
            'plan' => $plan->value,
            'plan_label' => $plan->label(),
            'is_premium' => $plan->isPaid(),
            'entitlements' => $this->plans->entitlements($plan),
            'effective_audio_quality' => $this->subscriptions->effectiveQualityFor($user)->value,
            'subscription' => $current !== null ? SubscriptionResource::make($current)->resolve() : null,
        ], 'Subscription retrieved');
    }

    /** POST /subscription — the simulated checkout. */
    public function store(SubscribeRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $currency = $request->currency()
            ?? $this->plans->currencyForCountry($user->country);

        return $this->respondCreated(
            SubscriptionResource::make(
                $this->subscriptions->subscribe($user, $request->plan(), $currency),
            ),
            'Subscription started',
        );
    }

    /**
     * DELETE /subscription
     *
     * Stops the renewal; entitlements survive to `current_period_end`, so the
     * cancelled subscription is returned rather than 204 — the client needs
     * that date to say "you keep Premium until…".
     */
    public function destroy(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            SubscriptionResource::make($this->subscriptions->cancel($this->user($request))),
            'Subscription cancelled',
        );
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
