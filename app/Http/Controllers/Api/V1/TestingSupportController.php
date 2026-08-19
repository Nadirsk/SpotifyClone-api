<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\PhoneOtp;
use App\Models\Subscription;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Test-only fixtures for the browser E2E suite.
 *
 * **Never routed in production.** The route group in `routes/api.php` is wrapped
 * in an environment check, so these endpoints do not exist outside `local` and
 * `testing`.
 *
 * `resetSubscription` is authenticated and acts only on the caller's own account
 * — an escaped route would be an embarrassment, not a breach.
 * `verifyBypassPhone` cannot be authenticated, because its whole purpose is to
 * run *before* a token exists; it is instead narrowed to refuse every number
 * except the configured OTP-bypass one, and it issues no token of its own. See
 * its own docblock.
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

    /**
     * POST /testing/verify-bypass-phone
     *
     * Marks the OTP-bypass phone as verified, so `POST /auth/login/phone` will
     * accept it — without sending an SMS.
     *
     * ## Why the E2E suite needs this
     *
     * Signing in by phone needs a *verified* number
     * (`OtpService::wasRecentlyVerified`), and verifying needs a pending
     * `phone_otps` row, which only `OtpService::send()` creates. That method
     * really does call the vendor: the bypass number keeps a fixed code so a
     * tester knows it without reading the SMS, but the message is still sent,
     * still costs a credit, and is capped at three per five minutes. A test suite
     * that mints its session that way bills real money per run and locks itself
     * out on the fourth one.
     *
     * This writes the row the flow is missing and nothing else.
     *
     * ## Why it is safe to be unauthenticated
     *
     * It has to be — there is no token yet. What keeps it narrow:
     *
     * - it exists only outside production, like everything else here;
     * - it refuses any number other than `services.textsms.bypass_phone`, and
     *   404s when no bypass number is configured at all, so it cannot be pointed
     *   at a real listener's phone;
     * - it returns no token and creates no account. It grants exactly what
     *   knowing the bypass code already grants, to a caller who must already know
     *   the bypass number.
     */
    public function verifyBypassPhone(Request $request): JsonResponse
    {
        $bypass = config('services.textsms.bypass_phone');

        if (! is_string($bypass) || $bypass === '') {
            throw new NotFoundHttpException('No bypass phone is configured.');
        }

        $phone = (string) $request->input('phone', $bypass);

        if (! hash_equals($bypass, $phone)) {
            throw new NotFoundHttpException('That is not the bypass phone.');
        }

        /*
         | Written as already-verified rather than sent-then-verified: the code is
         | never checked here, so hashing the bypass value into `otp_hash` would
         | be storing a credential for no reason. A fresh row each time, because
         | `wasRecentlyVerified` reads `verified_at` against a 30-minute window.
         */
        PhoneOtp::query()->create([
            'phone' => $bypass,
            'otp_hash' => Hash::make(Str::random(32)),
            'type' => 'signup',
            'expires_at' => Carbon::now()->addMinutes(30),
            'verified_at' => Carbon::now(),
        ]);

        return $this->respondSuccess(null, 'Bypass phone marked verified');
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
