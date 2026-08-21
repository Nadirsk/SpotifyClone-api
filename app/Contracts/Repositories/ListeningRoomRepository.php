<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\ListeningRole;
use App\Models\ListeningRoom;
use App\Models\ListeningRoomMember;
use App\Models\ListeningRoomQueueItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

interface ListeningRoomRepository
{
    /** A room that has not ended, by its shareable code. Null if unknown. */
    public function findLiveByCode(string $code): ?ListeningRoom;

    /**
     * A room that has not ended, by primary key — the lookup channel
     * authorization needs, since a channel name carries the id rather than the
     * human-typed code.
     */
    public function findLiveById(string $id): ?ListeningRoom;

    /**
     * Any room by code, ended or not — so the invite page can tell "this room
     * has finished" apart from "no such code", which are different answers to
     * the person holding the link.
     */
    public function findByCode(string $code): ?ListeningRoom;

    public function codeTaken(string $code): bool;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): ListeningRoom;

    /**
     * Writes the authoritative playback state and bumps `playback_version` in
     * the same statement.
     *
     * The version bump has to happen in SQL rather than as `$version + 1` in
     * PHP: two writes racing (a host double-pressing, or a skip landing while a
     * seek is in flight) would otherwise read the same version and write the
     * same successor, so a client that had already applied the first would
     * discard the second as stale and stop following the room.
     */
    public function writePlayback(
        ListeningRoom $room,
        ?string $songId,
        int $positionMs,
        bool $isPlaying,
        CarbonInterface $at,
    ): ListeningRoom;

    public function close(ListeningRoom $room, CarbonInterface $at): void;

    /**
     * The room with everything an API payload or a broadcast needs, eager-loaded.
     *
     * One method rather than each caller assembling its own `with()` list: lazy
     * loading throws outside production, so a caller that forgets one relation
     * fails at serialisation time in a way that is invisible until that exact
     * payload is exercised.
     */
    public function withState(ListeningRoom $room): ListeningRoom;

    /**
     * The room with only what the pre-join preview shows: the host, the current
     * track, and a count of who is present. Deliberately not `withState()` —
     * that loads the queue and every member, none of which a non-member may see.
     */
    public function withPreview(ListeningRoom $room): ListeningRoom;

    public function member(ListeningRoom $room, User $user): ?ListeningRoomMember;

    /** @return Collection<int, ListeningRoomMember> */
    public function activeMembers(ListeningRoom $room): Collection;

    public function activeMemberCount(ListeningRoom $room): int;

    /**
     * Joins, or re-joins: a member who left and came back reuses their row, so
     * `joined_at` keeps meaning "when this person first arrived".
     */
    public function joinMember(ListeningRoom $room, User $user, ListeningRole $role): ListeningRoomMember;

    /** False when the user was not an active member to begin with. */
    public function markLeft(ListeningRoom $room, User $user, CarbonInterface $at): bool;

    public function touchMember(ListeningRoom $room, User $user, CarbonInterface $at): void;

    /** Moves both the room's `host_user_id` and the member's role in one transaction. */
    public function assignHost(ListeningRoom $room, ListeningRoomMember $member): ListeningRoom;

    /**
     * Who should take over when the host leaves: the longest-present active
     * member who is not the person leaving. Null when the room is emptying.
     */
    public function nextHostCandidate(ListeningRoom $room, User $excluding): ?ListeningRoomMember;

    /**
     * Replaces the whole queue with `$songIds`, in the given order.
     *
     * A snapshot rather than a diff, because the queue's job here is to *match*
     * the host's player queue, and computing a minimal edit script against a
     * list that can be shuffled, extended and cleared buys nothing but ways to
     * disagree with it.
     *
     * @param  list<string>  $songIds
     */
    public function replaceQueue(ListeningRoom $room, array $songIds, User $addedBy): void;

    public function appendQueueItem(ListeningRoom $room, string $songId, User $addedBy): ListeningRoomQueueItem;

    /** False when the item does not exist, or belongs to another room. */
    public function removeQueueItem(ListeningRoom $room, string $itemId): bool;

    public function queueSize(ListeningRoom $room): int;

    /** Closes every live room that has been silent since `$before`. Returns how many. */
    public function closeStale(CarbonInterface $before): int;
}
