<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Listening\CreateListeningRoomRequest;
use App\Http\Resources\ListeningRoomPreviewResource;
use App\Http\Resources\ListeningRoomResource;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Listening\ListeningRoomService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Listen Together — rooms and membership.
 *
 * Playback and the queue are their own controllers, because they are the two
 * host-only surfaces and keeping them separate keeps the authorization
 * difference visible in the route file rather than buried in method bodies.
 */
final class ListeningRoomController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ListeningRoomService $rooms,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * POST /listening-rooms
     *
     * Platinum-exclusive (`config/plans.php` → `listen_together`), at explicit
     * product request. Checked here rather than in the service: this is a plan
     * gate, not a room-membership one — {@see ListeningRoomService}'s own doc is
     * explicit that it assumes its caller is allowed, and the thing being
     * asked here is "does this listener's plan cover the feature at all",
     * answered the same way `PlaybackService::download()` answers it.
     */
    public function store(CreateListeningRoomRequest $request): JsonResponse
    {
        $caller = $this->caller($request);

        $this->subscriptions->authorize($caller, 'listen_together');

        $room = $this->rooms->create(
            $caller,
            $request->songIds(),
            $request->initialPlayback(),
        );

        return $this->respondCreated(new ListeningRoomResource($room), 'Listening room created');
    }

    /**
     * GET /listening-rooms/{code}
     *
     * The full state, and the resync endpoint: everything a client needs after a
     * dropped connection arrives in this one answer.
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $room = $this->rooms->findLive($code);
        $caller = $this->caller($request);

        Gate::authorize('view', [$room, $this->rooms->isMember($room, $caller)]);

        return $this->respondSuccess(
            new ListeningRoomResource($this->rooms->state($room, $caller)),
            'Listening room retrieved',
        );
    }

    /**
     * GET /listening-rooms/{code}/preview
     *
     * Open to any authenticated listener rather than to members, because this is
     * what an invite link opens to — the whole point is that the person looking
     * at it has not joined yet. Ended rooms answer 410 from `findLive`, which is
     * what lets the invite page say "this room has ended" instead of pretending
     * the code was wrong.
     */
    public function preview(string $code): JsonResponse
    {
        $room = $this->rooms->findLive($code);

        return $this->respondSuccess(
            new ListeningRoomPreviewResource($this->rooms->preview($room)),
            'Listening room preview retrieved',
        );
    }

    /**
     * POST /listening-rooms/{code}/join
     *
     * Still no `ListeningRoomPolicy` gate here — joining stays the one action a
     * non-member is allowed to take, so room-membership authorization has
     * nothing to check. What *is* checked is the same plan gate `store()`
     * applies: Listen Together is Platinum-exclusive for everyone in the room,
     * not just its host, so a Free/Standard/Student listener cannot ride along
     * on someone else's room by invite link either.
     */
    public function join(Request $request, string $code): JsonResponse
    {
        $room = $this->rooms->findLive($code);
        $caller = $this->caller($request);

        $this->subscriptions->authorize($caller, 'listen_together');

        return $this->respondSuccess(
            new ListeningRoomResource($this->rooms->join($room, $caller)),
            'Joined listening room',
        );
    }

    /**
     * POST /listening-rooms/{code}/leave
     *
     * 204 whether or not the caller was still in the room, and deliberately not
     * gated by a policy.
     *
     * Leaving is fired by the button *and* by the page being closed, so the same
     * departure arrives twice as a matter of course — and a client firing a
     * beacon on unload cannot read a response, let alone handle a 403 on it.
     * There is nothing to protect here either: for somebody who is not a member
     * this is a no-op, and answering 204 to them reveals nothing that the
     * preview endpoint does not already.
     */
    public function leave(Request $request, string $code): JsonResponse
    {
        $this->rooms->leave($this->rooms->findLive($code), $this->caller($request));

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
