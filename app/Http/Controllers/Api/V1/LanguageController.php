<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LanguageResource;
use App\Models\Language;
use App\Services\Cache\CacheService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Language reference list, used to populate the filter dropdown in
 * 05_API_SPECIFICATION §16.
 *
 * Same reasoning as GenreController: immutable reference data, no service or
 * repository layer because neither would do anything.
 */
final class LanguageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CacheService $cache,
    ) {}

    /**
     * GET /api/v1/languages
     */
    public function index(): JsonResponse
    {
        $languages = $this->cache->remember(
            'song',
            'languages:all',
            static fn (): Collection => Language::query()->orderBy('name')->get(),
        );

        return $this->respondSuccess(LanguageResource::collection($languages));
    }
}
