<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Language\StoreLanguageRequest;
use App\Http\Requests\Admin\Language\UpdateLanguageRequest;
use App\Http\Resources\LanguageResource;
use App\Services\Catalog\AdminLanguageService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin management of the language taxonomy. Every route here sits behind
 * ['auth:sanctum', 'admin']. Reuses the public LanguageResource.
 */
final class AdminLanguageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminLanguageService $languages,
    ) {}

    /**
     * GET /api/v1/admin/languages
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->languages->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            LanguageResource::class,
        );
    }

    /**
     * POST /api/v1/admin/languages
     */
    public function store(StoreLanguageRequest $request): JsonResponse
    {
        $language = $this->languages->create($request->validated());

        return $this->respondCreated(new LanguageResource($language), 'Language created');
    }

    /**
     * PUT /api/v1/admin/languages/{id}
     */
    public function update(UpdateLanguageRequest $request, string $id): JsonResponse
    {
        $language = $this->languages->find($id);

        return $this->respondSuccess(
            new LanguageResource($this->languages->update($language, $request->validated())),
            'Language updated',
        );
    }

    /**
     * DELETE /api/v1/admin/languages/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $this->languages->delete($this->languages->find($id));

        return $this->respondNoContent();
    }
}
