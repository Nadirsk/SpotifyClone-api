<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The plan catalog, for the pricing page and the Free-vs-Premium comparison
 * table.
 *
 * Public: a signed-out visitor has to be able to see what Premium costs before
 * creating an account. The caller is still resolved when a token is present
 * (the default guard is `sanctum`), because a signed-in listener's currency
 * comes from their profile country and their current plan should be marked.
 */
final class PlanController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PlanCatalog $plans,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * GET /plans
     *
     * `?currency=USD` overrides the country-derived default, which is what the
     * plans page's currency switcher sends.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $requested = strtoupper((string) $request->query('currency', ''));
        $currency = $requested !== '' && $this->plans->isSupportedCurrency($requested)
            ? $requested
            : $this->plans->currencyForCountry($user?->country);

        $current = $user !== null ? $this->subscriptions->planFor($user)->value : null;

        return $this->respondSuccess([
            'currency' => $currency,
            'currencies' => $this->plans->currencies(),
            'discount_percent' => (int) round(((float) config('plans.discount_rate')) * 100),
            'current_plan' => $current,
            /*
             | Whether the introductory two-months pricing may be shown. Decided
             | server-side because it depends on subscription history the client
             | cannot see, and because a client-side check would be trivially
             | bypassed to claim the offer twice.
             */
            'intro_offer_available' => $user !== null && ! $this->subscriptions->hasEverSubscribed($user),
            'plans' => $this->plans->all($currency),
        ], 'Plans retrieved');
    }
}
