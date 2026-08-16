<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Test-only fixtures for the browser E2E suite.
 *
 * **Never routed in production.** The route group in `routes/api.php` is wrapped
 * in an environment check, so these endpoints do not exist outside `local` and
 * `testing`. They are still authenticated and still act only on the caller's own
 * account — an escaped route would be an embarrassment, not a breach.
 *
 * ## Why this exists rather than the suite using the real endpoints
 *
 * Resetting a subscription is the problem. `DELETE /subscription` *cancels*, and
 * a cancelled subscription deliberately keeps its entitlements until
 * `current_period_end` — which is the correct product behaviour and exactly what
 * makes it useless for "put this account back on Free before the next test".
 * The alternative is a fresh user per spec, but the demo account is the one the
 * catalog fixtures and the seeded library hang off.
 */
final class TestingSupportController extends Controller
{
    use ApiResponse;

    /**
     * POST /testing/reset-subscription
     *
     * Expires every subscription the caller has, putting them on Free
     * immediately rather than at the end of a paid period.
     */
    public function resetSubscription(Request $request): JsonResponse
    {
        $expired = Subscription::query()
            ->where('user_id', $this->user($request)->id)
            ->update([
                'status' => SubscriptionStatus::Expired,
                'current_period_end' => Carbon::now()->subSecond(),
            ]);

        return $this->respondSuccess(['expired' => $expired], 'Subscriptions reset');
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
