<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\LanguageResource;
use App\Services\Cache\CacheService;
use App\Services\Catalog\TaxonomyService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Language reference list, used to populate the filter dropdown in
 * 05_API_SPECIFICATION §16.
 *
 * Same shape as GenreController, and for the same reason: each row carries a
 * song count and one real cover, so a client can order the Browse grid by how
 * much catalog is actually behind each language. Language is the taxonomy this
 * catalog really has — nearly every song is tagged with one, while `genre_id`
 * is null throughout — which is why that grid is built from this list.
 */
final class LanguageController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CacheService $cache,
        private readonly TaxonomyService $taxonomy,
    ) {}

    /**
     * GET /api/v1/languages
     */
    public function index(): JsonResponse
    {
        $languages = $this->cache->remember(
            'song',
            'languages:all:v2',
            fn (): Collection => $this->taxonomy->languages(),
        );

        return $this->respondSuccess(LanguageResource::collection($languages));
    }

    /**
     * GET /api/v1/languages/popular-albums
     *
     * Every language that has albums, with its most popular ones. Backs the
     * `/popular-by-country` shelves in a single request — see
     * TaxonomyService::popularAlbumsByLanguage for what this replaced.
     *
     * Deliberately *not* run through CacheService, unlike everything else in
     * this controller. The cache store is the database, and this payload —
     * every language crossed with a dozen hydrated albums and their artists —
     * serialises past MySQL's `max_allowed_packet`, so writing it fails the
     * whole request with an `08S01` communication-link error rather than
     * quietly skipping the cache. It does not need one either: the work is two
     * indexed queries, and the response is already cached in front of this by
     * the Next route handler's `Cache-Control` and the adapter's own
     * `revalidate`.
     */
    public function popularAlbums(Request $request): JsonResponse
    {
        $perLanguage = min(24, max(1, (int) $request->query('per_language', '12')));

        $payload = array_values(array_map(static fn (array $entry): array => [
            'language' => LanguageResource::make($entry['language']),
            'albums' => AlbumResource::collection($entry['albums']),
        ], $this->taxonomy->popularAlbumsByLanguage($perLanguage)));

        return $this->respondSuccess($payload);
    }
}
