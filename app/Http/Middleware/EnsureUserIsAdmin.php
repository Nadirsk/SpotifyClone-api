<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every `admin/*` route. Must run after `auth:sanctum` so
 * `$request->user()` is populated — a listener's otherwise-valid token is
 * rejected here with a 403, not a 401.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            throw new AuthorizationException('Admin access required.');
        }

        return $next($request);
    }
}
