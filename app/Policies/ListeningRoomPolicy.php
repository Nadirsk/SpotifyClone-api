<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ListeningRoom;
use App\Models\User;

/**
 * Who may see a room, and who may drive it.
 *
 * The split is the security boundary of the whole feature: a participant who
 * could send playback events would be able to take over any room they were
 * invited to, and a non-member who could read one would be able to watch a
 * private listening session from its code alone.
 *
 * Enforced here — on the server — rather than by hiding the buttons. The
 * frontend does hide them, because a control that does nothing is worse than no
 * control, but that is a courtesy to the listener and not the check. See the
 * feature test that posts playback as a participant.
 *
 * `$isMember` is computed by the caller from the membership rows rather than
 * queried here, following {@see PlaylistPolicy}: it keeps this class comparing
 * plain scalars, so it stays testable against bare models with nothing persisted.
 */
final class ListeningRoomPolicy
{
    /**
     * Reading the room: its playback state, its queue, who is in it.
     *
     * Members only. A room code is short enough to guess at scale, so "knows the
     * code" is not treated as authorization for anything except the pre-join
     * preview, which deliberately carries almost nothing (see
     * ListeningRoomPreviewResource).
     */
    public function view(User $user, ListeningRoom $room, bool $isMember = false): bool
    {
        return $isMember || $room->isHost($user);
    }

    /**
     * Play, pause, seek, change track.
     *
     * Host only, and checked against the room's own `host_user_id` rather than
     * the member row's role, because that column is the one the succession logic
     * moves and it is therefore the only copy that cannot be stale.
     */
    public function control(User $user, ListeningRoom $room): bool
    {
        return $room->isLive() && $room->isHost($user);
    }

    /** Adding, removing or reordering the room queue — host only, same as control. */
    public function modifyQueue(User $user, ListeningRoom $room): bool
    {
        return $this->control($user, $room);
    }

    /*
     | There is deliberately no `leave` method.
     |
     | Leaving is not an authorized action but an idempotent one: it is fired by
     | the button and again by the page unloading, and for anyone who is not a
     | member it does nothing at all. Gating it produced a 403 on the second of
     | two legitimate calls — see the controller.
     */
}
