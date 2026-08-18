<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTO\CatalogQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Playlist\AddPlaylistSongRequest;
use App\Http\Requests\Playlist\StorePlaylistRequest;
use App\Http\Requests\Playlist\UpdatePlaylistRequest;
use App\Http\Resources\PlaylistResource;
use App\Models\User;
use App\Services\Library\PlaylistService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * 05_API_SPECIFICATION §9.
 */
final class PlaylistController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PlaylistService $playlists,
    ) {}

    /**
     * GET /playlists — the viewer's own playlists plus everyone's public ones.
     * Works for guests, who see only the public ones.
     */
    public function index(Request $request): JsonResponse
    {
        /*
         | CatalogQuery is reused purely for its page/limit clamping against
         | config('music.pagination'); its filter and sort fields do not apply
         | to a library listing.
         */
        $query = CatalogQuery::fromRequest($request);

        return $this->respondPaginated(
            $this->playlists->list($this->viewer($request), $query->page, $query->limit),
            PlaylistResource::class,
            'Playlists retrieved',
        );
    }

    /** POST /playlists */
    public function store(StorePlaylistRequest $request): JsonResponse
    {
        $playlist = $this->playlists->create($this->owner($request), $request->validated());

        return $this->respondCreated(new PlaylistResource($playlist), 'Playlist created');
    }

    /** GET /playlists/{playlist} */
    public function show(Request $request, string $playlist): JsonResponse
    {
        $model = $this->playlists->show($playlist);

        Gate::authorize('view', [$model, $model->isCollaborator($this->viewer($request))]);

        return $this->respondSuccess(new PlaylistResource($model), 'Playlist retrieved');
    }

    /** PUT /playlists/{playlist} */
    public function update(UpdatePlaylistRequest $request, string $playlist): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('update', $model);

        return $this->respondSuccess(
            new PlaylistResource($this->playlists->update($model, $request->validated())),
            'Playlist updated',
        );
    }

    /** DELETE /playlists/{playlist} */
    public function destroy(string $playlist): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('delete', $model);

        $this->playlists->delete($model);

        return $this->respondNoContent();
    }

    /** POST /playlists/{playlist}/songs */
    public function addSong(AddPlaylistSongRequest $request, string $playlist): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('addSong', [$model, $model->isCollaborator($this->viewer($request))]);

        return $this->respondCreated(
            new PlaylistResource($this->playlists->addSong($model, $request->songId())),
            'Song added to playlist',
        );
    }

    /** DELETE /playlists/{playlist}/songs/{song} */
    public function removeSong(Request $request, string $playlist, string $song): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('removeSong', [$model, $model->isCollaborator($this->viewer($request))]);

        $this->playlists->removeSong($model, $song);

        return $this->respondNoContent();
    }

    private function viewer(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function owner(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
