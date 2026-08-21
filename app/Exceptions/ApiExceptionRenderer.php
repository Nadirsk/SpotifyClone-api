<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Centralised API error handling (04_BACKEND_ARCHITECTURE §14).
 *
 * Every failure leaving an /api route is normalised into
 * `{ success: false, message, errors }` so the client only ever parses one
 * error shape.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(static function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    private static function toResponse(Throwable $e): JsonResponse
    {
        [$status, $message, $errors] = match (true) {
            $e instanceof ValidationException => [
                422,
                'Validation failed',
                $e->errors(),
            ],
            $e instanceof AuthenticationException => [
                401,
                'Unauthenticated',
                [],
            ],
            $e instanceof AuthorizationException => [
                403,
                $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized',
                [],
            ],
            // Route model binding misses surface as ModelNotFound, not 404.
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [
                404,
                'Resource not found',
                [],
            ],
            $e instanceof TooManyRequestsHttpException => [
                429,
                'Too many requests',
                [],
            ],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $e->getMessage() !== '' ? $e->getMessage() : 'Request failed',
                [],
            ],
            default => [
                500,
                /*
                 | Never leak an exception message in production — it can expose
                 | table names, file paths and query fragments.
                 */
                config('app.debug') === true ? $e->getMessage() : 'Server error',
                [],
            ],
        };

        $payload = [
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ];

        /*
         | The one error in the app that carries a body. The client cannot act on
         | "you are signed in on too many devices" without being told *which*
         | devices and given something to act with, and `errors` is field-keyed
         | validation messages — the wrong shape and the wrong meaning. See the
         | exception's own docblock.
         */
        if ($e instanceof SessionLimitReachedException) {
            $payload['session_limit'] = $e->payload();
        }

        if ($status === 500 && config('app.debug') === true) {
            $payload['debug'] = [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        /*
         | Carry the exception's own headers through. Only `Retry-After` uses
         | this today — set by ProviderUnavailableException and by Laravel's own
         | throttle rejection — and dropping it would leave a client that has
         | just been told "come back later" with no idea when, which in practice
         | means retrying immediately and making it worse.
         */
        $headers = $e instanceof HttpExceptionInterface
            ? array_intersect_key($e->getHeaders(), ['Retry-After' => true])
            : [];

        return response()->json($payload, $status, $headers);
    }
}
