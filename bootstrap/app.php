<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureUserIsAdmin;
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
    /*
     | Channel authorization for the listening rooms.
     |
     | Registered explicitly rather than by passing `channels:` to
     | withRouting() above, because that helper's auto-registration puts
     | /broadcasting/auth behind the `web` group - cookie and session auth,
     | which this API deliberately does not have (see the statefulApi note
     | below). Echo would be presenting a bearer token to a route that reads
     | a session, and be told it is a guest on every single subscribe.
     |
     | The `api/v1` prefix is not cosmetic either: config/cors.php allows
     | `api/*` only, so an auth endpoint outside that prefix is unreachable
     | from the browser as soon as the frontend is on another origin.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
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

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiExceptionRenderer::register($exceptions);
    })->create();
