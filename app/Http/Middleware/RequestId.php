<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID, echoed back as `X-Request-Id` and
 * attached to every log line the request produces.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        /*
         | Honour a caller-supplied ID so a trace can span the Next.js frontend
         | and this API, but only if it looks like an ID — otherwise an attacker
         | could inject arbitrary text into our logs.
         */
        $incoming = (string) $request->header(self::HEADER, '');
        $requestId = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
