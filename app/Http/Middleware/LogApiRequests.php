<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Structured access logging for the API (04_BACKEND_ARCHITECTURE §15).
 */
class LogApiRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('api.request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'user_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            /*
             | Query strings only — never the request body. Bodies carry
             | passwords and reset tokens, and logs are not a secret store.
             */
            'query' => $request->query(),
        ]);

        return $response;
    }
}
