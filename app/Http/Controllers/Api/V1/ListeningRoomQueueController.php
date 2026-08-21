<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Listening\AddQueueSongRequest;
use App\Http\Requests\Listening\ReplaceQueueRequest;
use App\Http\Resources\ListeningRoomResource;
use App\Models\User;
use App\Services\Listening\ListeningRoomService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The room queue — host only.
 *
 * `PUT` replaces the whole thing and is what the host's player calls whenever its
 * own queue changes; `POST` and `DELETE` are the single-track edits made from the
 * room UI. Reordering has no endpoint of its own: a reorder *is* a replacement,
 * and a dedicated one would be a second way to express the same write.
 */
final class ListeningRoomQueueController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ListeningRoomService $rooms,
    ) {}

    /** PUT /listening-rooms/{code}/queue */
    public function replace(ReplaceQueueRequest $request, string $code): JsonResponse
    {
        $room = $this->rooms->findLive($code);

        Gate::authorize('modifyQueue', $room);

        return $this->respondSuccess(
            new ListeningRoomResource($this->rooms->replaceQueue($room, $this->caller($request), $request->songIds())),
            'Room queue updated',
        );
    }

    /** POST /listening-rooms/{code}/queue */
    public function store(AddQueueSongRequest $request, string $code): JsonResponse
    {
        $room = $this->rooms->findLive($code);

        Gate::authorize('modifyQueue', $room);

        return $this->respondCreated(
            new ListeningRoomResource($this->rooms->addToQueue($room, $this->caller($request), $request->songId())),
            'Song added to room queue',
        );
    }

    /**
     * DELETE /listening-rooms/{code}/queue/{item}
     *
     * Answers 200 with the room rather than 204, because the caller is the host
     * who is looking at the queue they just edited and would otherwise have to
     * refetch it to redraw. (The frontend's `destroyFor` helper exists for
     * exactly these endpoints.)
     */
    public function destroy(string $code, string $item): JsonResponse
    {
        $room = $this->rooms->findLive($code);

        Gate::authorize('modifyQueue', $room);

        return $this->respondSuccess(
            new ListeningRoomResource($this->rooms->removeFromQueue($room, $item)),
            'Song removed from room queue',
        );
    }

    /** @return User Guaranteed by the route's auth:sanctum middleware. */
    private function caller(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
