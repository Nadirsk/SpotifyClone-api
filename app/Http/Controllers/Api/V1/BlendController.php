<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Blend\StoreBlendRequest;
use App\Http\Requests\Blend\UpdateBlendRequest;
use App\Http\Resources\BlendResource;
use App\Http\Resources\PlaylistResource;
use App\Models\User;
use App\Services\Blend\BlendService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Blend — no source in 01-11, built on explicit request. Every route requires
 * auth:sanctum (see routes/api.php's own note): unlike playlists, a Blend has
 * no public or unlisted tier to fall back to for a guest.
 */
final class BlendController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BlendService $blends,
    ) {}

    /** GET /blends — the caller's own Blends, newest first. */
    public function index(Request $request): JsonResponse
    {
        $query = CatalogQuery::fromRequest($request);

        return $this->respondPaginated(
            $this->blends->list($this->caller($request), $query->page, $query->limit),
            BlendResource::class,
            'Blends retrieved',
        );
    }

    /** POST /blends */
    public function store(StoreBlendRequest $request): JsonResponse
    {
        $blend = $this->blends->create($this->caller($request), $request->validated());

        return $this->respondCreated(new BlendResource($blend), 'Blend created');
    }

    /** GET /blends/{blend} */
    public function show(Request $request, string $blend): JsonResponse
    {
        $model = $this->blends->show($blend);

        Gate::authorize('view', [$model, $model->isMember($this->caller($request))]);

        return $this->respondSuccess(new BlendResource($model), 'Blend retrieved');
    }

    /** PUT /blends/{blend} — rename. */
    public function update(UpdateBlendRequest $request, string $blend): JsonResponse
    {
        $model = $this->blends->find($blend);

        Gate::authorize('update', $model);

        return $this->respondSuccess(
            new BlendResource($this->blends->rename($model, $request->validated()['title'])),
            'Blend renamed',
        );
    }

    /** DELETE /blends/{blend} */
    public function destroy(string $blend): JsonResponse
    {
        $model = $this->blends->find($blend);

        Gate::authorize('delete', $model);

        $this->blends->delete($model);

        return $this->respondNoContent();
    }

    /**
     * POST /blends/{blend}/refresh — manual "Refresh Blend". Rate-limited by
     * the route's own `throttle:2,60` (12_SCOPE_OF_WORK §19: "prevent users
     * from repeatedly triggering expensive generation requests").
     */
    public function refresh(Request $request, string $blend): JsonResponse
    {
        $model = $this->blends->find($blend);

        Gate::authorize('regenerate', [$model, $model->isMember($this->caller($request))]);

        return $this->respondSuccess(new BlendResource($this->blends->regenerate($model)), 'Blend refreshed');
    }

    /** POST /blends/{blend}/save — copies the current tracklist into a new playlist owned by the caller. */
    public function save(Request $request, string $blend): JsonResponse
    {
        $model = $this->blends->find($blend);
        $caller = $this->caller($request);

        Gate::authorize('save', [$model, $model->isMember($caller)]);

        return $this->respondCreated(
            new PlaylistResource($this->blends->saveAsPlaylist($model, $caller)),
            'Saved as playlist',
        );
    }

    /** POST /blends/{blend}/leave */
    public function leave(Request $request, string $blend): JsonResponse
    {
        $model = $this->blends->find($blend);
        $caller = $this->caller($request);

        Gate::authorize('leave', [$model, $model->isMember($caller)]);

        $this->blends->leave($model, $caller);

        return $this->respondNoContent();
    }

    /** DELETE /blends/{blend}/members/{user} — creator only. */
    public function removeMember(string $blend, string $user): JsonResponse
    {
        $model = $this->blends->find($blend);

        Gate::authorize('manageMembers', $model);

        $this->blends->removeMember($model, $user);

        return $this->respondNoContent();
    }

    /** @return User Guaranteed by the route's auth:sanctum middleware. */
    private function caller(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
