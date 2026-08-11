<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicUserResource;
use App\Models\User;
use App\Services\Library\UserFollowService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A public profile and its social graph. `show`/`followers`/`following` are
 * public reads — mirrors `PlaylistController`'s index/show being public while
 * its mutations require a token (see `routes/api.php`'s own note on why).
 */
final class UserFollowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserFollowService $follows,
    ) {}

    /** GET /users/{id} */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(
            new PublicUserResource($this->follows->show($id)),
            'Profile retrieved',
        );
    }

    /** GET /users/{id}/followers */
    public function followers(Request $request, string $id): JsonResponse
    {
        $query = CatalogQuery::fromRequest($request);

        return $this->respondPaginated(
            $this->follows->paginateFollowers($id, $query->page, $query->limit),
            PublicUserResource::class,
            'Followers retrieved',
        );
    }

    /** GET /users/{id}/following */
    public function following(Request $request, string $id): JsonResponse
    {
        $query = CatalogQuery::fromRequest($request);

        return $this->respondPaginated(
            $this->follows->paginateFollowing($id, $query->page, $query->limit),
            PublicUserResource::class,
            'Following retrieved',
        );
    }

    /** POST /users/{id}/follow */
    public function store(Request $request, string $id): JsonResponse
    {
        $this->follows->follow($this->user($request), $id);

        return $this->respondCreated(null, 'Followed');
    }

    /** DELETE /users/{id}/follow */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->follows->unfollow($this->user($request), $id);

        return $this->respondNoContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
