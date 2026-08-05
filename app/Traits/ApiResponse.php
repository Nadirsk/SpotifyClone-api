<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single place the API response envelope from 05_API_SPECIFICATION §2–3 is
 * built. Controllers should never hand-roll a response array.
 */
trait ApiResponse
{
    protected function respondSuccess(
        mixed $data = null,
        string $message = 'Request successful',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->resolveData($data),
        ], $status);
    }

    protected function respondCreated(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return $this->respondSuccess($data, $message, Response::HTTP_CREATED);
    }

    /**
     * 204 carries no body by definition, so the envelope is intentionally absent.
     */
    protected function respondNoContent(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    protected function respondError(
        string $message,
        array $errors = [],
        int $status = Response::HTTP_BAD_REQUEST,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }

    /**
     * Wraps a paginator in the envelope, moving the page metadata into the
     * `pagination` shape from 05_API_SPECIFICATION §15 rather than Laravel's
     * default `meta`/`links`.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>|null  $resource
     */
    protected function respondPaginated(
        LengthAwarePaginator $paginator,
        ?string $resource = null,
        string $message = 'Request successful',
    ): JsonResponse {
        $items = $paginator->getCollection();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resource !== null
                ? $resource::collection($items)->resolve()
                : $items->toArray(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Resources need resolving here rather than being returned raw, otherwise
     * Laravel wraps them in its own `data` key and we end up nesting twice.
     */
    private function resolveData(mixed $data): mixed
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            return $data->resolve();
        }

        return $data;
    }
}
