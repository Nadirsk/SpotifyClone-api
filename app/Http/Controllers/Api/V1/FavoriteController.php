<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\User;
use App\Services\Library\FavoriteService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 05_API_SPECIFICATION §10.
 *
 * The path carries the *song* id, not a favourite id, and the owning user is
 * always taken from the token — there is no addressable favourite record a
 * caller could point at someone else's account.
 */
final class FavoriteController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FavoriteService $favorites,
    ) {}

    /** GET /favorites */
    public function index(Request $request): JsonResponse
    {
        /*
         | CatalogQuery is reused purely for its page/limit clamping against
         | config('music.pagination'); its filter and sort fields do not apply.
         */
        $query = CatalogQuery::fromRequest($request);

        return $this->respondPaginated(
            $this->favorites->paginate($this->user($request), $query->page, $query->limit),
            SongResource::class,
            'Favorites retrieved',
        );
    }

    /** POST /favorites/{song} */
    public function store(Request $request, string $song): JsonResponse
    {
        $this->favorites->add($this->user($request), $song);

        // 201 whether or not the row was new: the requested end state — this
        // song is favourited — holds either way.
        return $this->respondCreated(null, 'Song added to favorites');
    }

    /** DELETE /favorites/{song} */
    public function destroy(Request $request, string $song): JsonResponse
    {
        $this->favorites->remove($this->user($request), $song);

        return $this->respondNoContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
