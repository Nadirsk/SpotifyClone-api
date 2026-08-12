<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureModels();
        $this->configurePasswordResetUrl();
    }

    /**
     * Per 05_API_SPECIFICATION §17.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            if ($user !== null) {
                return Limit::perMinute((int) config('music.rate_limits.authenticated'))
                    ->by((string) $user->getKey());
            }

            /*
             | Guests are bucketed by IP. Behind Cloudflare this is only correct
             | if trusted proxies are configured, otherwise every guest shares
             | the proxy's IP and one visitor can throttle everyone.
             */
            return Limit::perMinute((int) config('music.rate_limits.guest'))
                ->by((string) $request->ip());
        });

        /*
         | Belt-and-braces on top of OtpService's own per-phone lockout
         | (5-10 attempts per 10 minutes there): this one is by IP, so a
         | script rotating through phone numbers from one machine still hits
         | a wall, and it is cheap enough to check before OtpService ever
         | touches the database or the SMS vendor.
         |
         | 30, not 6: this key is shared by every request from one IP
         | regardless of phone number, so anyone behind NAT — an office, a
         | campus wifi, a mobile carrier's shared address — pools their limit
         | with every other guest on it. 6 was tripping on a handful of
         | unrelated users sending OTPs minutes apart from the same address;
         | 30 still catches a script blasting through numbers (the per-phone
         | lockout is the real ceiling on any one number) while giving normal
         | concurrent traffic on a shared IP room to breathe.
         */
        RateLimiter::for('otp-send', function (Request $request): Limit {
            return Limit::perMinutes(10, 30)->by((string) $request->ip());
        });

        RateLimiter::for('otp-verify', function (Request $request): Limit {
            return Limit::perMinutes(10, 15)->by((string) $request->ip());
        });
    }

    private function configureModels(): void
    {
        /*
         | Fail loudly on an unloaded relationship rather than silently issuing
         | an N+1 query. Relaxed in production so a missed eager-load degrades
         | performance instead of returning a 500 to a user.
         */
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
    }

    /**
     * Without this, `ResetPassword::toMail()` falls back to `route('password.reset', ...)`,
     * which does not exist in this API-only app — this app has no Blade reset
     * form, the Next.js frontend does — so link generation would throw and
     * `AuthService::forgotPassword()`'s catch would silently swallow it,
     * leaving no usable link in the email (or, locally, the log) at all.
     * `cors.allowed_origins.0` is reused rather than a second env read because
     * it is already this app's one source of truth for "where the frontend is."
     */
    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontendUrl = rtrim((string) config('cors.allowed_origins.0'), '/');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }
}
