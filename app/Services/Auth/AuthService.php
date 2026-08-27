<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Repositories\UserRepository;
use App\Events\UserRegistered;
use App\Exceptions\SessionLimitReachedException;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Authentication use cases from 05_API_SPECIFICATION §4.
 *
 * Every failure path here is deliberately uninformative: the API must never
 * confirm or deny that a given email has an account. See the comments on
 * `login()` and `forgotPassword()`.
 *
 * One failure is the exception, and only because it happens *after* the
 * credential check has already passed: the per-plan device cap. Every path that
 * hands out a session funnels through `issueToken()`, which delegates to
 * {@see DeviceSessionService} — so a login can succeed and still not produce a
 * token, because the account is signed in on as many devices as it pays for.
 * `resolveSessionConflict()` is how that gets unstuck.
 */
final class AuthService
{
    /**
     * The only message any failed login returns, whatever the actual cause —
     * unknown email, wrong password, or an OAuth-only account. Distinct
     * messages would turn the login endpoint into an account-enumeration
     * oracle.
     */
    private const INVALID_CREDENTIALS = 'The provided credentials are incorrect.';

    private const THROTTLED = 'Too many login attempts. Please try again later.';

    private const OAUTH_FAILED = 'Google authentication failed. Please try signing in again.';

    /**
     * Same shape as DeviceSessionService's conflict ticket: a random key the
     * callback mints and the frontend's landing page redeems once, seconds
     * later. Needed because the Google callback is a full browser navigation
     * to this API's own origin — there is no Next.js code running on that
     * response to catch a token, only on whatever page the browser lands on
     * next.
     */
    private const GOOGLE_EXCHANGE_PREFIX = 'auth:google:exchange:';

    private const GOOGLE_EXCHANGE_TTL_SECONDS = 60;

    /**
     * Per email+IP lockout, tighter than the global per-minute throttle in
     * 05_API_SPECIFICATION §17. The global limit is generous enough that an
     * attacker could still spray a few hundred passwords a minute at one
     * account; this caps it at five.
     */
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 60;

    /**
     * A real bcrypt digest of a throwaway string, compared against when no user
     * row is found so that an unknown email costs the same wall-clock time as a
     * known one. Without it, response timing leaks which emails are registered.
     */
    private const TIMING_EQUALISATION_HASH = '$2y$12$dYxxVMlV.scZJKxdWrE6A./LoUUeUSiLmaWyI7Alt74VcB5iV9eci';

    public function __construct(
        private readonly UserRepository $users,
        private readonly OtpService $otp,
        private readonly EmailLoginCodeService $emailLoginCode,
        private readonly DeviceSessionService $devices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        $user = $this->users->create([
            'name' => $data['name'],
            'email' => $data['email'],
            // Hashed by the `password` cast on the model, not here.
            'password' => $data['password'],
            'country' => $data['country'] ?? null,
            'language' => $data['language'] ?? config('app.locale'),
        ]);

        UserRegistered::dispatch($user);

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
        ];
    }

    /**
     * Phone-native account: no email, no password — the phone OTP flow is the
     * only credential this account will ever have. Not in
     * 01_PRODUCT_REQUIREMENTS.md §6 (email + Google only), added at explicit
     * request once phone OTP itself became real.
     *
     * @param  array{phone: string, name: string, country?: string|null, language?: string|null}  $data
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function registerWithPhone(array $data): array
    {
        /*
         | The Form Request cannot check this itself — it has no reason to
         | know about OtpService — so the guard lives here: without it,
         | anyone could POST a made-up phone number straight to this endpoint
         | and get an account for a number they never proved they control.
         */
        if (! $this->otp->wasRecentlyVerified($data['phone'])) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number has not been verified. Please verify it again.'],
            ]);
        }

        $user = $this->users->create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => null,
            'password' => null,
            'country' => $data['country'] ?? null,
            'language' => $data['language'] ?? config('app.locale'),
        ]);

        $this->otp->linkToUser($data['phone'], $user);

        UserRegistered::dispatch($user);

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
        ];
    }

    /**
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password, string $ip): array
    {
        $key = $this->throttleKey($email, $ip);

        if (RateLimiter::tooManyAttempts($key, self::LOGIN_MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => [self::THROTTLED]]);
        }

        $user = $this->users->findByEmail($email);

        /*
         | A null password means an OAuth-only account. Checked explicitly
         | rather than letting it fall through to Hash::check(), which would be
         | given a null needle and raise a deprecation on PHP 8.3 — and, worse,
         | could pass with a hasher that treats null as an empty string.
         */
        if ($user === null || $user->password === null) {
            Hash::check($password, self::TIMING_EQUALISATION_HASH);
            RateLimiter::hit($key, self::LOGIN_DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => [self::INVALID_CREDENTIALS]]);
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::LOGIN_DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => [self::INVALID_CREDENTIALS]]);
        }

        RateLimiter::clear($key);

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
        ];
    }

    /**
     * The phone flow's counterpart to `login()`: there is no password to
     * check, so a verified OTP is the entire proof of identity. Deliberately
     * does not create an account for an unrecognised phone — that would blur
     * login and sign-up into one action, and the sign-up wizard collects a
     * name this endpoint never sees.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function loginWithPhone(string $phone): array
    {
        if (! $this->otp->wasRecentlyVerified($phone)) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number has not been verified. Please verify it again.'],
            ]);
        }

        $user = $this->users->findByPhone($phone);

        if ($user === null) {
            throw ValidationException::withMessages([
                'phone' => ['No account exists for this phone number. Please sign up instead.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
        ];
    }

    /**
     * @throws ValidationException Propagated from EmailLoginCodeService::send()
     *                             — a throttle hit, an address with no
     *                             account, or a transport failure. See that
     *                             method for why it reports these rather than
     *                             returning quietly as it once did.
     */
    public function sendEmailLoginCode(string $email): void
    {
        $this->emailLoginCode->send($email);
    }

    /**
     * The email flow's counterpart to `loginWithPhone()`: a verified code
     * stands in for a password. Unlike the phone flow there is no separate
     * "verify" step for the caller to have already done — every email
     * belongs to an existing account already, so verifying the code and
     * logging in are the same action here.
     *
     * @return array{user: User, token: string}
     *
     * @throws ValidationException
     */
    public function loginWithEmailCode(string $email, string $code): array
    {
        $user = $this->emailLoginCode->verify($email, $code);

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
        ];
    }

    /**
     * Finish a login that hit the plan's device cap: sign the chosen devices
     * out, then mint the session that `issueToken()` refused.
     *
     * The ticket is the authentication here — see
     * {@see SessionLimitReachedException} for why the caller has nothing else to
     * present at this point. If the account is somehow *still* over its cap
     * afterwards — they sent fewer ids than `sessions_to_free` asked for, or
     * another device signed in meanwhile — `issueToken()` throws again with a
     * fresh list and a fresh ticket. Clients must adopt that new payload; the
     * old ticket has been spent and will only ever 422 from here on.
     *
     * @param  list<int>  $sessionIds
     * @return array{user: User, token: string}
     */
    public function resolveSessionConflict(string $ticket, array $sessionIds): array
    {
        $user = $this->devices->resolveConflict($ticket, $sessionIds);

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
        ];
    }

    /**
     * Revokes only the token that made this request. Other devices stay signed
     * in — logging out of a phone should not sign the user out of a laptop.
     * Signing *another* device out is `DeviceSessionService::revoke()`, reached
     * through `DELETE /auth/sessions/{id}`.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    /**
     * Always completes silently, whether or not the email is registered — the
     * caller returns one fixed response either way. A "no such user" branch
     * here would leak account existence to anyone with a signup form.
     *
     * Delivery failures are swallowed for the same reason: an SMTP error can
     * only occur when the account exists, so surfacing it would restore the
     * oracle that the rest of this method removes.
     */
    public function forgotPassword(string $email): void
    {
        try {
            Password::sendResetLink(['email' => $email]);
        } catch (Throwable $e) {
            Log::error('Password reset link delivery failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{email: string, token: string, password: string, password_confirmation: string}  $credentials
     *
     * @throws ValidationException
     */
    public function resetPassword(array $credentials): void
    {
        $status = Password::reset($credentials, function (User $user, string $password): void {
            $this->users->update($user, ['password' => $password]);

            /*
             | A reset is the remedy for a compromised account, so every
             | existing token dies with it — otherwise an attacker who already
             | holds a bearer token keeps access after the owner "locks them
             | out". The client must sign in again.
             */
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            /*
             | An invalid token and an unknown email are reported identically.
             | The token is unguessable, so this is not a usability cost, but
             | separate messages would confirm which emails have accounts.
             */
            throw ValidationException::withMessages([
                'email' => ['This password reset token is invalid or has expired.'],
            ]);
        }
    }

    /**
     * The provider's consent URL. Returned to the client as JSON rather than
     * issued as a 302 because the API is stateless and the Next.js client owns
     * the navigation (08_FRONTEND_ARCHITECTURE §8).
     */
    public function googleRedirectUrl(): string
    {
        return Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
    }

    /**
     * @return array{user: User, token: string}
     */
    public function loginWithGoogle(): array
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            /*
             | A replayed, expired or tampered `code` makes Socialite throw. That
             | is a failed authentication, not a server fault, so it must not
             | reach the handler's 500 branch.
             */
            Log::warning('Google OAuth callback rejected.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new HttpException(Response::HTTP_UNAUTHORIZED, self::OAUTH_FAILED);
        }

        $email = $googleUser->getEmail();

        // Accounts are keyed on email; without one there is nothing to link to.
        if ($email === null || $email === '') {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, self::OAUTH_FAILED);
        }

        $user = $this->users->findOrCreateFromOauth(
            provider: 'google',
            providerUserId: (string) $googleUser->getId(),
            email: $email,
            name: $googleUser->getName() ?? $email,
            avatar: $googleUser->getAvatar(),
        );

        if ($user->wasRecentlyCreated) {
            UserRegistered::dispatch($user);
        }

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
        ];
    }

    /**
     * What `AuthController::googleCallback()` actually calls: runs
     * `loginWithGoogle()` above, then stashes the result behind a one-time code
     * instead of handing the token back directly — see GOOGLE_EXCHANGE_PREFIX
     * for why the callback itself cannot deliver the token.
     */
    public function completeGoogleLogin(): string
    {
        $result = $this->loginWithGoogle();

        $code = Str::random(64);

        Cache::put(self::GOOGLE_EXCHANGE_PREFIX.$code, [
            'user_id' => $result['user']->getKey(),
            'token' => $result['token'],
        ], self::GOOGLE_EXCHANGE_TTL_SECONDS);

        return $code;
    }

    /**
     * Redeems a `completeGoogleLogin()` code. One-time by construction: it is
     * forgotten as soon as it is read, whether or not it turns out to be valid,
     * so a leaked or replayed code (a shared link, a browser-history entry) is
     * only ever good for one login.
     *
     * @return array{user: User, token: string}
     */
    public function exchangeGoogleCode(string $code): array
    {
        $payload = Cache::get(self::GOOGLE_EXCHANGE_PREFIX.$code);

        Cache::forget(self::GOOGLE_EXCHANGE_PREFIX.$code);

        if (! is_array($payload) || ! isset($payload['user_id'], $payload['token'])) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, self::OAUTH_FAILED);
        }

        try {
            $user = $this->users->findOrFail((string) $payload['user_id']);
        } catch (ModelNotFoundException) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, self::OAUTH_FAILED);
        }

        return [
            'user' => $user,
            'token' => (string) $payload['token'],
        ];
    }

    /**
     * The single choke point for minting a session, which is what lets the
     * per-plan device cap be enforced in one place instead of at each of the six
     * paths that reach here (register, register-with-phone, password login,
     * phone OTP, email code, Google).
     *
     * @throws SessionLimitReachedException when the account already holds as
     *                                      many live sessions as its plan
     *                                      allows. Both register paths reach
     *                                      this too, harmlessly: a brand-new
     *                                      account has no sessions to be over
     *                                      the cap with.
     */
    private function issueToken(User $user): string
    {
        return $this->devices->issueFor($user);
    }

    /**
     * Bucketed by email *and* IP: by email alone, an attacker could lock a
     * victim out of their own account at will. The email is hashed so the cache
     * store never holds addresses in the clear.
     */
    private function throttleKey(string $email, string $ip): string
    {
        return 'auth:login:'.hash('xxh128', mb_strtolower($email).'|'.$ip);
    }
}
