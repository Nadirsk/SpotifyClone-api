<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\DeviceSessionService;
use App\Services\Billing\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The devices an account is signed in on, and signing them out.
 *
 * The management half of the per-plan device cap — the enforcement half lives in
 * {@see DeviceSessionService}, and the login-time conflict flow is
 * `AuthController::resolveSessionConflict()`. Every route here is authenticated:
 * these act on the caller's own sessions, and the one case where somebody needs
 * to sign a device out *without* a token is the conflict flow, which has its own
 * single-use ticket instead.
 */
final class DeviceSessionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DeviceSessionService $devices,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Every live session, plus the ceiling they are counted against so the
     * client can render "2 of 3 devices" without a second request or a second
     * copy of the plan table.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->respondSuccess([
            'max_sessions' => $this->subscriptions->maxSessionsFor($user),
            'sessions' => $this->devices->listFor($user, $this->currentTokenId($request)),
        ], 'Request successful');
    }

    /**
     * Sign one device out.
     *
     * Passing your own session id is allowed and behaves exactly like a logout —
     * refusing it would be a special case whose only effect is a confusing
     * error for someone doing something reasonable.
     */
    public function destroy(Request $request, int $session): JsonResponse
    {
        $this->devices->revoke($this->currentUser($request), $session);

        return $this->respondSuccess(null, 'Device signed out.');
    }

    /**
     * Sign every other device out, keeping this one. The "not you? secure your
     * account" button — which is worthless if it also logs out the person
     * pressing it.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $count = $this->devices->revokeOthers(
            $this->currentUser($request),
            $this->currentTokenId($request),
        );

        return $this->respondSuccess(
            ['signed_out' => $count],
            $count === 1 ? '1 other device signed out.' : "{$count} other devices signed out.",
        );
    }

    /**
     * Id of the token that made this request, so the list can mark one row as
     * the reader's own. Null only if Sanctum authenticated by session cookie
     * rather than a bearer token, which this API never does.
     */
    private function currentTokenId(Request $request): ?int
    {
        $token = $this->currentUser($request)->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->id : null;
    }

    /**
     * Guard clause only: these routes sit behind `auth:sanctum`, so a null user
     * means the middleware was omitted rather than a real anonymous request.
     */
    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
