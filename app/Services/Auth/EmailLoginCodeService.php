<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Repositories\UserRepository;
use App\Mail\LoginCodeMail;
use App\Models\EmailLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Passwordless email login's one-time code — the email counterpart to
 * OtpService, sharing its shape (rate-limited send, hashed-at-rest code,
 * rate-limited verify) but simpler: every code always belongs to an
 * existing user, so there is no equivalent of the phone flow's separate
 * "verify" vs "register/login" split — one successful `verify()` call here
 * is the entire login.
 */
final class EmailLoginCodeService
{
    private const CODE_MIN = 100000;

    private const CODE_MAX = 999999;

    /** Matches the reference email's own copy: "valid for 20 minutes". */
    private const TTL_MINUTES = 20;

    private const SEND_MAX_ATTEMPTS = 3;

    private const SEND_DECAY_SECONDS = 600;

    private const VERIFY_MAX_ATTEMPTS = 5;

    private const VERIFY_DECAY_SECONDS = 600;

    private const THROTTLED = 'Too many attempts. Please try again later.';

    private const INVALID_OR_EXPIRED = 'That code is incorrect or has expired.';

    public function __construct(
        private readonly UserRepository $users,
    ) {}

    /**
     * Always completes silently whether or not the email is registered —
     * the caller shows one fixed message either way, the same
     * anti-enumeration shape as AuthService::forgotPassword(). A throttle
     * hit is swallowed rather than thrown for the same reason: surfacing it
     * would tell an anonymous caller "a code is already pending for this
     * address", which is itself account-existence information.
     */
    public function send(string $email): void
    {
        $key = $this->rateLimitKey('send', $email);

        if (RateLimiter::tooManyAttempts($key, self::SEND_MAX_ATTEMPTS)) {
            return;
        }

        RateLimiter::hit($key, self::SEND_DECAY_SECONDS);

        $user = $this->users->findByEmail($email);

        if ($user === null || $user->email === null) {
            return;
        }

        $code = (string) random_int(self::CODE_MIN, self::CODE_MAX);

        EmailLoginCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        Mail::to($user->email)->send(new LoginCodeMail($code, self::TTL_MINUTES));
    }

    /**
     * @throws ValidationException On lockout or a wrong/expired code — both
     *                              messages are identical for the same
     *                              enumeration-resistance reason as
     *                              OtpService::verify().
     */
    public function verify(string $email, string $code): User
    {
        $key = $this->rateLimitKey('verify', $email);

        if (RateLimiter::tooManyAttempts($key, self::VERIFY_MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => [self::THROTTLED]]);
        }

        $user = $this->users->findByEmail($email);

        $record = $user === null ? null : EmailLoginCode::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if ($record === null || ! Hash::check($code, $record->code_hash)) {
            RateLimiter::hit($key, self::VERIFY_DECAY_SECONDS);

            throw ValidationException::withMessages(['code' => [self::INVALID_OR_EXPIRED]]);
        }

        RateLimiter::clear($key);

        $record->forceFill(['verified_at' => now()])->save();

        /** @var User $user */
        return $user;
    }

    /** Bucketed by email alone, hashed so the cache store never holds addresses in the clear. */
    private function rateLimitKey(string $action, string $email): string
    {
        return "email-login:{$action}:".hash('xxh128', mb_strtolower($email));
    }
}
