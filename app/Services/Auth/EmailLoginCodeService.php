<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Repositories\UserRepository;
use App\Mail\LoginCodeMail;
use App\Models\EmailLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

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

    /**
     * 5 minutes, matching OtpService's send window — the two are kept in
     * lockstep so "how long am I locked out for" has one answer whichever
     * way the visitor signed in.
     *
     * The window runs from the first send, not the last (Laravel's
     * RateLimiter does not extend the TTL on later hits), so three sends in
     * quick succession lock the address for the rest of it. At 10 minutes,
     * where this started, that was a long time to strand someone whose only
     * mistake was tapping "Resend code" twice.
     */
    private const SEND_DECAY_SECONDS = 300;

    private const VERIFY_MAX_ATTEMPTS = 5;

    /**
     * Left at 10 minutes on purpose. Unlike the send window above, this one
     * is the brake on guessing a live 6-digit code, so its cost is paid by
     * an attacker, not by an honest visitor who already has the code in
     * front of them.
     */
    private const VERIFY_DECAY_SECONDS = 600;

    private const THROTTLED = 'Too many attempts. Please try again later.';

    /** Worded to match `AuthService::loginWithPhone()`'s phone equivalent, so the two flows read the same. */
    private const NO_ACCOUNT = 'No account exists for this email. Please sign up instead.';

    /** Verbatim from OtpService::SEND_FAILED — the same situation, and the visitor's next step is the same. */
    private const SEND_FAILED = 'Could not send the code. Please try again.';

    private const INVALID_OR_EXPIRED = 'That code is incorrect or has expired.';

    public function __construct(
        private readonly UserRepository $users,
    ) {}

    /**
     * Reports every failure to the caller rather than completing silently.
     *
     * This deliberately gives up the anti-enumeration shape it was first
     * built with (an unregistered address used to return quietly, so the
     * endpoint could not be used to test which emails have accounts). Two
     * reasons that property was not worth its cost here:
     *
     *  - It was never actually held app-wide. `AuthService::loginWithPhone()`
     *    already answers "No account exists for this phone number" outright,
     *    so the oracle existed on the phone side regardless — paying for it
     *    on the email side bought nothing but an inconsistency.
     *  - Silence is a dead end for the visitor. A mistyped address produced
     *    a "code sent" screen and a code that could never arrive, with no
     *    way to find out why. That is the failure this method now avoids.
     *
     * The error arrives here, at send time, rather than after the visitor has
     * waited for and typed a code — the phone flow's ordering, which defers
     * the same message to `loginWithPhone()`, only wastes their time. It also
     * means no code row and no mail are needed for an address with no
     * account: `email_login_codes.user_id` is NOT NULL precisely because
     * every row belongs to a real user, and this keeps that true.
     *
     * The logs below are kept for diagnosing a code that was sent but never
     * received. Levels are unchanged: ordinary outcomes are `info`, so a
     * server on the production LOG_LEVEL=warning surfaces only the delivery
     * failure. Drop LOG_LEVEL to `info` while chasing a missing code.
     *
     * The code itself is never logged. To read a real one, point MAIL_MAILER
     * at `log`: the whole rendered email lands in the log channel, which also
     * isolates whether a missing code is this app's fault or the SMTP host's.
     *
     * @throws ValidationException On lockout, an address with no account, or
     *                             a transport failure — all three leave the
     *                             visitor with no code, so all three are
     *                             things the caller must show them.
     */
    public function send(string $email): void
    {
        $key = $this->rateLimitKey('send', $email);

        if (RateLimiter::tooManyAttempts($key, self::SEND_MAX_ATTEMPTS)) {
            Log::info('Email login code not sent: send throttle already spent.', [
                'email' => $this->maskEmail($email),
                'max_attempts' => self::SEND_MAX_ATTEMPTS,
                'available_in_seconds' => RateLimiter::availableIn($key),
            ]);

            throw ValidationException::withMessages(['email' => [self::THROTTLED]]);
        }

        RateLimiter::hit($key, self::SEND_DECAY_SECONDS);

        $user = $this->users->findByEmail($email);

        if ($user === null || $user->email === null) {
            Log::info('Email login code not sent: no account holds that address.', [
                'email' => $this->maskEmail($email),
                'reason' => $user === null ? 'user_not_found' : 'user_has_no_email',
            ]);

            throw ValidationException::withMessages(['email' => [self::NO_ACCOUNT]]);
        }

        $code = (string) random_int(self::CODE_MIN, self::CODE_MAX);

        EmailLoginCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        try {
            Mail::to($user->email)->send(new LoginCodeMail($code, self::TTL_MINUTES));
        } catch (Throwable $e) {
            Log::error('Email login code delivery failed.', [
                'email' => $this->maskEmail($user->email),
                'user_id' => $user->id,
                'mailer' => config('mail.default'),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            /*
             | Surfaced, not swallowed: the visitor is waiting on a code that
             | is now never coming, so telling them to retry is the only
             | useful outcome. The row created above stays behind unused and
             | expires on its own — harmless, and cheaper than holding the
             | insert open across the send.
             */
            throw ValidationException::withMessages(['email' => [self::SEND_FAILED]]);
        }

        Log::info('Email login code sent.', [
            'email' => $this->maskEmail($user->email),
            'user_id' => $user->id,
            'mailer' => config('mail.default'),
        ]);
    }

    /**
     * @throws ValidationException On lockout or a wrong/expired code — both
     *                             messages are identical for the same
     *                             enumeration-resistance reason as
     *                             OtpService::verify().
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

    /**
     * Enough of the address to tell which attempt a log line belongs to,
     * without writing it in full. The rate-limit keys above already avoid
     * storing addresses in the clear; a `daily` log channel on the server
     * would otherwise keep 14 days of them.
     */
    private function maskEmail(string $email): string
    {
        $at = mb_strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.mb_substr($email, $at);
    }
}
