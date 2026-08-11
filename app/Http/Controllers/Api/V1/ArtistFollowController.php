<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArtistResource;
use App\Models\User;
use App\Services\Library\ArtistFollowService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mirrors FavoriteController exactly. The path carries the *artist* id, not
 * a follow-row id, and the owning user is always taken from the token — there
 * is no addressable follow record a caller could point at someone else's
 * account.
 */
final class ArtistFollowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ArtistFollowService $follows,
    ) {}

    /** GET /artists/followed */
    public function index(Request $request): JsonResponse
    {
        $query = CatalogQuery::fromRequest($request);

        return $this->respondPaginated(
            $this->follows->paginate($this->user($request), $query->page, $query->limit),
            ArtistResource::class,
            'Followed artists retrieved',
        );
    }

    /** POST /artists/{artist}/follow */
    public function store(Request $request, string $artist): JsonResponse
    {
        $this->follows->follow($this->user($request), $artist);

        // 201 whether or not the row was new: the requested end state — this
        // artist is followed — holds either way.
        return $this->respondCreated(null, 'Artist followed');
    }

    /** DELETE /artists/{artist}/follow */
    public function destroy(Request $request, string $artist): JsonResponse
    {
        $this->follows->unfollow($this->user($request), $artist);

        return $this->respondNoContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
