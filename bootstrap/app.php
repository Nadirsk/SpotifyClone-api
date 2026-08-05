<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogApiRequests;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         | Laravel does not put `throttle` in the api group by default, so the
         | limits from 05_API_SPECIFICATION §17 have to be attached explicitly.
         | It goes first so that rejected requests never reach the app.
         */
        $middleware->api(prepend: [
            'throttle:api',
        ]);

        $middleware->api(append: [
            RequestId::class,
            ForceJsonResponse::class,
            SetLocale::class,
            LogApiRequests::class,
        ]);

        /*
         | Deliberately NOT calling $middleware->statefulApi(). Sanctum issues
         | bearer tokens to the Next.js client (08_FRONTEND_ARCHITECTURE §8), so
         | the API stays stateless. Enabling stateful mode would pull in cookie
         | auth and a CSRF token exchange the client does not perform.
         */

        /*
         | There is no `login` named route in this API-only app, but
         | Illuminate\Foundation\Configuration\ApplicationBuilder registers a
         | default guest-redirect of `fn () => route('login')` before this
         | closure ever runs. `auth:sanctum` sits in Laravel's fixed middleware
         | priority list ahead of our own ForceJsonResponse, so on an
         | unauthenticated request it fires before the Accept header is forced
         | to JSON — request()->expectsJson() is still false at that point, so
         | the default callback's route('login') call throws
         | RouteNotFoundException instead of ever producing a 401. Overriding
         | it to return null lets AuthenticationException reach
         | ApiExceptionRenderer untouched.
         */
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
