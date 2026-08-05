<?php

declare(strict_types=1);

namespace App\Providers;

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
}
