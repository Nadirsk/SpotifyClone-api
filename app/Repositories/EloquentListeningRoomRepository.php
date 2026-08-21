<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ListeningRoomRepository;
use App\Enums\ListeningRole;
use App\Models\ListeningRoom;
use App\Models\ListeningRoomMember;
use App\Models\ListeningRoomQueueItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentListeningRoomRepository implements ListeningRoomRepository
{
    /**
     * Everything a room payload serialises, in one load.
     *
     * `queue.song.artist` and `queue.song.album` are here because
     * `ListeningRoomQueueItemResource` embeds `SongResource`, which reads both
     * through `whenLoaded` — without them a fifty-track room queue serialises
     * to fifty songs with no artist names on them. `queue.addedBy` is the same
     * reasoning for the queue row's own "Added by" name.
     *
     * @var list<string>
     */
    private const STATE_RELATIONS = [
        'host',
        'currentSong.artist',
        'currentSong.album',
        'members.user',
        'queue.song.artist',
        'queue.song.album',
        'queue.addedBy',
    ];

    public function findLiveByCode(string $code): ?ListeningRoom
    {
        return ListeningRoom::query()
            ->where('room_code', $code)
            ->whereNull('ended_at')
            ->first();
    }

    public function findLiveById(string $id): ?ListeningRoom
    {
        return ListeningRoom::query()
            ->whereKey($id)
            ->whereNull('ended_at')
            ->first();
    }

    public function findByCode(string $code): ?ListeningRoom
    {
        return ListeningRoom::query()->where('room_code', $code)->first();
    }

    public function codeTaken(string $code): bool
    {
        return ListeningRoom::query()->where('room_code', $code)->exists();
    }

    public function create(array $attributes): ListeningRoom
    {
        return ListeningRoom::query()->create($attributes);
    }

    public function writePlayback(
        ListeningRoom $room,
        ?string $songId,
        int $positionMs,
        bool $isPlaying,
        CarbonInterface $at,
    ): ListeningRoom {
        /*
         | Formatted explicitly rather than handed over as a Carbon.
         |
         | The query builder formats dates with the connection's own format,
         | which is 'Y-m-d H:i:s' — no fractional seconds. Passing the instance
         | would therefore truncate this write to the second, quietly undoing the
         | one thing `position_at` is a datetime(3) for. The model declares the
         | same format for its own writes; see ListeningRoom::$dateFormat.
         */
        $stamp = $at->format('Y-m-d H:i:s.v');

        ListeningRoom::query()
            ->whereKey($room->getKey())
            ->update([
                'current_song_id' => $songId,
                'position_ms' => $positionMs,
                'is_playing' => $isPlaying,
                'position_at' => $stamp,
                'playback_version' => DB::raw('playback_version + 1'),
                'updated_at' => $stamp,
            ]);

        return $room->refresh();
    }

    public function close(ListeningRoom $room, CarbonInterface $at): void
    {
        ListeningRoom::query()
            ->whereKey($room->getKey())
            ->whereNull('ended_at')
            ->update(['ended_at' => $at, 'is_playing' => false]);

        $room->refresh();
    }

    public function withState(ListeningRoom $room): ListeningRoom
    {
        /*
         | The members relation is constrained to those still present, and that
         | has to happen in the eager load rather than on the loaded collection:
         | filtering afterwards leaves the unfiltered rows in the relation for
         | whatever reads it next, and `members` is read by both the resource and
         | the broadcast payload.
         */
        return $room->load([
            ...self::STATE_RELATIONS,
            'members' => static fn (HasMany $query): HasMany => $query
                ->whereNull('left_at')
                ->orderBy('joined_at'),
        ]);
    }

    public function withPreview(ListeningRoom $room): ListeningRoom
    {
        return $room
            ->loadCount(['members' => static fn (Builder $query): Builder => $query->whereNull('left_at')])
            ->load(['host', 'currentSong.artist', 'currentSong.album']);
    }

    public function member(ListeningRoom $room, User $user): ?ListeningRoomMember
    {
        return ListeningRoomMember::query()
            ->where('room_id', $room->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    public function activeMembers(ListeningRoom $room): Collection
    {
        return ListeningRoomMember::query()
            ->with('user')
            ->where('room_id', $room->getKey())
            ->whereNull('left_at')
            ->orderBy('joined_at')
            ->get();
    }

    public function activeMemberCount(ListeningRoom $room): int
    {
        return ListeningRoomMember::query()
            ->where('room_id', $room->getKey())
            ->whereNull('left_at')
            ->count();
    }

    public function joinMember(ListeningRoom $room, User $user, ListeningRole $role): ListeningRoomMember
    {
        $now = now();
        $member = $this->member($room, $user);

        if ($member !== null) {
            /*
             | Re-joining. `joined_at` is left alone on purpose — it is what
             | orders the host-succession queue, and refreshing it would move
             | someone who briefly dropped out to the back of it.
             */
            $member->forceFill([
                'role' => $role,
                'last_seen_at' => $now,
                'left_at' => null,
            ])->save();

            return $member->refresh();
        }

        return ListeningRoomMember::query()->create([
            'room_id' => $room->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'joined_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    public function markLeft(ListeningRoom $room, User $user, CarbonInterface $at): bool
    {
        return ListeningRoomMember::query()
            ->where('room_id', $room->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->update(['left_at' => $at, 'last_seen_at' => $at]) > 0;
    }

    public function touchMember(ListeningRoom $room, User $user, CarbonInterface $at): void
    {
        ListeningRoomMember::query()
            ->where('room_id', $room->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('left_at')
            ->update(['last_seen_at' => $at]);
    }

    public function assignHost(ListeningRoom $room, ListeningRoomMember $member): ListeningRoom
    {
        DB::transaction(function () use ($room, $member): void {
            ListeningRoomMember::query()
                ->where('room_id', $room->getKey())
                ->where('role', ListeningRole::Host->value)
                ->update(['role' => ListeningRole::Participant->value]);

            ListeningRoomMember::query()
                ->whereKey($member->getKey())
                ->update(['role' => ListeningRole::Host->value]);

            ListeningRoom::query()
                ->whereKey($room->getKey())
                ->update(['host_user_id' => $member->user_id]);
        });

        return $room->refresh();
    }

    public function nextHostCandidate(ListeningRoom $room, User $excluding): ?ListeningRoomMember
    {
        return ListeningRoomMember::query()
            ->with('user')
            ->where('room_id', $room->getKey())
            ->whereNull('left_at')
            ->where('user_id', '!=', $excluding->getKey())
            ->orderBy('joined_at')
            ->first();
    }

    public function replaceQueue(ListeningRoom $room, array $songIds, User $addedBy): void
    {
        $now = now();

        $rows = [];

        foreach (array_values($songIds) as $index => $songId) {
            $rows[] = [
                // `insert()` bypasses the model, so HasUuids never runs and the
                // key has to be minted here — with the same ordered UUID the
                // trait would have produced, so rows inserted by either path
                // stay in insertion order under a primary-key sort.
                'id' => (string) Str::orderedUuid(),
                'room_id' => $room->getKey(),
                'song_id' => $songId,
                'added_by' => $addedBy->getKey(),
                'queue_position' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        /*
         | Delete-then-insert inside one transaction. The queue is a snapshot, so
         | there is no state worth preserving row by row, and doing it in a
         | transaction is what stops a reader (a listener joining at that
         | instant) from seeing the empty middle of the swap.
         */
        DB::transaction(function () use ($room, $rows): void {
            ListeningRoomQueueItem::query()->where('room_id', $room->getKey())->delete();

            if ($rows !== []) {
                // Chunked because a 200-track queue in one INSERT is a wide
                // statement, and `max_allowed_packet` is not ours to assume.
                foreach (array_chunk($rows, 50) as $chunk) {
                    ListeningRoomQueueItem::query()->insert($chunk);
                }
            }
        });
    }

    public function appendQueueItem(ListeningRoom $room, string $songId, User $addedBy): ListeningRoomQueueItem
    {
        $tail = ListeningRoomQueueItem::query()
            ->where('room_id', $room->getKey())
            ->max('queue_position');

        return ListeningRoomQueueItem::query()->create([
            'room_id' => $room->getKey(),
            'song_id' => $songId,
            'added_by' => $addedBy->getKey(),
            // Null means an empty queue, which is slot 0. Casting the null to an
            // int and adding one instead would leave slot 0 permanently vacant
            // and put the first track of every room second in its own order.
            'queue_position' => $tail === null ? 0 : ((int) $tail) + 1,
        ]);
    }

    public function removeQueueItem(ListeningRoom $room, string $itemId): bool
    {
        return ListeningRoomQueueItem::query()
            ->where('room_id', $room->getKey())
            ->whereKey($itemId)
            ->delete() > 0;
    }

    public function queueSize(ListeningRoom $room): int
    {
        return ListeningRoomQueueItem::query()->where('room_id', $room->getKey())->count();
    }

    public function closeStale(CarbonInterface $before): int
    {
        return ListeningRoom::query()
            ->whereNull('ended_at')
            ->where('updated_at', '<', $before)
            ->update(['ended_at' => now(), 'is_playing' => false]);
    }
}
