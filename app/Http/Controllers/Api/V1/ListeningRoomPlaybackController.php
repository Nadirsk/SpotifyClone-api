<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Listening\UpdatePlaybackRequest;
use App\Http\Resources\ListeningRoomResource;
use App\Models\User;
use App\Services\Listening\ListeningRoomService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The host's playback writes — the only endpoint in this feature that is hit
 * more than once per session, and the one whose latency the listener hears.
 *
 * It is a REST call rather than a client-to-client WebSocket message on purpose.
 * Clients cannot be trusted to agree on state, and a participant that could
 * publish to the channel directly could take over any room it was invited to;
 * routing the write through the server is what makes `ListeningRoomPolicy` the
 * single place that decides who drives.
 */
final class ListeningRoomPlaybackController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ListeningRoomService $rooms,
    ) {}

    /**
     * POST /listening-rooms/{code}/playback
     *
     * Returns the room *without* its queue or members loaded — the response is
     * the host's own confirmation of a state they already have on screen, and
     * re-serialising a 200-track queue on every seek would put the queue on the
     * wire dozens of times a minute. `ListeningRoomResource` guards every
     * relation with `whenLoaded`, so the same resource simply emits less.
     */
    public function update(UpdatePlaybackRequest $request, string $code): JsonResponse
    {
        $room = $this->rooms->findLive($code);
        $caller = $this->caller($request);

        Gate::authorize('control', $room);

        $room = $this->rooms->updatePlayback($room, $caller, $request->command());

        return $this->respondSuccess(new ListeningRoomResource($room), 'Playback updated');
    }

    /** @return User Guaranteed by the route's auth:sanctum middleware. */
    private function caller(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
