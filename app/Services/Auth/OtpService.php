<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Sms\SmsGateway;
use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Phone sign-up's OTP send/verify (not in 05_API_SPECIFICATION — see the
 * docblock on the phone sign-up screens for why this exists outside the
 * documented scope). Deliberately mirrors AuthService::login()'s shape: a
 * per-key RateLimiter lockout around the actual check, hit on failure,
 * cleared on success.
 */
final class OtpService
{
    /**
     * 6 digits, matching the Spotify reference screens. Not configurable —
     * a length change here has to stay in lockstep with VerifyOtpRequest's
     * `digits:6` rule and the frontend's 6-box input, so it is a constant
     * everything else derives from, not a knob.
     *
     * Risk accepted knowingly: the DLT template this vendor sends through
     * was registered with a 4-digit variable (see the SMS code the phone
     * sign-up flow was built from). Telecom-side DLT scrubbing can silently
     * drop a message whose variable length does not match what was
     * registered, so a real (non-bypass) number may not actually receive
     * this 6-digit code even though the request to the vendor succeeds.
     */
    private const OTP_LENGTH_MIN = 100000;

    private const OTP_LENGTH_MAX = 999999;

    private const SEND_MAX_ATTEMPTS = 3;

    private const SEND_DECAY_SECONDS = 600;

    private const VERIFY_MAX_ATTEMPTS = 5;

    private const VERIFY_DECAY_SECONDS = 600;

    private const THROTTLED = 'Too many attempts. Please try again later.';

    private const SEND_FAILED = 'Could not send the code. Please try again.';

    private const INVALID_OR_EXPIRED = 'That code is incorrect or has expired.';

    public function __construct(
        private readonly SmsGateway $gateway,
    ) {}

    /**
     * @throws ValidationException On lockout or a vendor-side send failure —
     *                              both are things the caller must show the
     *                              visitor, not swallow.
     */
    public function send(string $phone): void
    {
        $key = $this->rateLimitKey('send', $phone);

        if (RateLimiter::tooManyAttempts($key, self::SEND_MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['phone' => [self::THROTTLED]]);
        }

        RateLimiter::hit($key, self::SEND_DECAY_SECONDS);

        $otp = $this->generate($phone);

        // The bypass phone now goes through the real vendor like any other
        // number — it only keeps a fixed OTP (see generate()) so a tester
        // still knows the code without reading the SMS. It is no longer
        // exempt from the send throttle either: it costs a real credit now,
        // so it needs the same abuse guard everyone else gets.
        $sent = $this->gateway->send($phone, $this->message($otp));

        if (! $sent) {
            throw ValidationException::withMessages(['phone' => [self::SEND_FAILED]]);
        }

        // Only recorded once the vendor actually accepted it — a row for a
        // message nobody received would be a code that can never be redeemed.
        PhoneOtp::query()->create([
            'phone' => $phone,
            'otp_hash' => Hash::make($otp),
            'type' => 'signup',
            'expires_at' => now()->addMinutes((int) config('services.textsms.ttl_minutes', 5)),
        ]);
    }

    /**
     * @throws ValidationException On lockout or a wrong/expired code. The two
     *                              share one message deliberately — telling
     *                              a caller "no code was ever sent to this
     *                              number" versus "wrong code" would let a
     *                              phone-number guessing script fish for
     *                              which numbers have a pending OTP at all.
     */
    public function verify(string $phone, string $code): void
    {
        $bypass = $this->isBypassPhone($phone);
        $key = $this->rateLimitKey('verify', $phone);

        if (! $bypass && RateLimiter::tooManyAttempts($key, self::VERIFY_MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['otp' => [self::THROTTLED]]);
        }

        $record = PhoneOtp::query()
            ->where('phone', $phone)
            ->where('type', 'signup')
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if ($record === null || ! Hash::check($code, $record->otp_hash)) {
            if (! $bypass) {
                RateLimiter::hit($key, self::VERIFY_DECAY_SECONDS);
            }

            throw ValidationException::withMessages(['otp' => [self::INVALID_OR_EXPIRED]]);
        }

        RateLimiter::clear($key);

        $record->forceFill(['verified_at' => now()])->save();
    }

    /**
     * True when `$phone` has a `phone_otps` row verified within the last
     * `$withinMinutes` — the check a later "finish creating the account" step
     * uses to trust that this phone was actually confirmed, without minting
     * and passing around a separate short-lived token for it.
     */
    public function wasRecentlyVerified(string $phone, int $withinMinutes = 30): bool
    {
        return PhoneOtp::query()
            ->where('phone', $phone)
            ->where('type', 'signup')
            ->whereNotNull('verified_at')
            ->where('verified_at', '>', now()->subMinutes($withinMinutes))
            ->exists();
    }

    /**
     * Backfills `user_id` on every `phone_otps` row for `$phone` once
     * registration actually lands — see the create-table migration's own
     * comment for why that column starts out null. Called once, right after
     * the account is created.
     */
    public function linkToUser(string $phone, User $user): void
    {
        PhoneOtp::query()->where('phone', $phone)->update(['user_id' => $user->id]);
    }

    /**
     * The one fixed (phone, code) pair — configured, not hardcoded, so it
     * costs nothing to disable in production and nothing in source ties this
     * class to any one person's real number.
     */
    private function generate(string $phone): string
    {
        if ($this->isBypassPhone($phone)) {
            return (string) config('services.textsms.bypass_otp');
        }

        return (string) random_int(self::OTP_LENGTH_MIN, self::OTP_LENGTH_MAX);
    }

    /**
     * Whether `$phone` is the one configured (phone, code) test pair —
     * checked here rather than inline everywhere it matters (`generate()`
     * and `verify()`'s throttle skip) so those stay in lockstep instead of
     * separate `hash_equals` calls drifting apart.
     */
    private function isBypassPhone(string $phone): bool
    {
        $bypassPhone = config('services.textsms.bypass_phone');

        return is_string($bypassPhone) && $bypassPhone !== '' && hash_equals($bypassPhone, $phone);
    }

    private function message(string $otp): string
    {
        return "Dear User, Your OTP for Aaibuzz registration is {$otp}. Please use this OTP to complete your registration. Do not share this OTP with anyone.";
    }

    /** Bucketed by phone alone: unlike login, there is no second dimension (an email) to combine it with. */
    private function rateLimitKey(string $action, string $phone): string
    {
        return "otp:{$action}:".hash('xxh128', $phone);
    }
}
