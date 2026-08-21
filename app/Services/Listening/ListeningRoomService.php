<?php

declare(strict_types=1);

namespace App\Services\Listening;

use App\Contracts\Repositories\ListeningRoomRepository;
use App\DTO\PlaybackCommand;
use App\Enums\ListeningRole;
use App\Events\Listening\RoomMembershipChanged;
use App\Events\Listening\RoomPlaybackUpdated;
use App\Events\Listening\RoomQueueUpdated;
use App\Exceptions\DomainException;
use App\Http\Resources\ListeningRoomPreviewResource;
use App\Models\ListeningRoom;
use App\Models\User;
use App\Policies\ListeningRoomPolicy;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Listen Together — rooms, membership, and the room's authoritative playback
 * state.
 *
 * ## The model
 *
 * Clients make the sound; the server decides what the sound should be. A host's
 * player reports what it did ("track 4, 95.42 seconds in, playing") and this
 * service records it together with the instant it was true, then tells the room.
 * Everybody else extrapolates from that pair against their own corrected clock.
 *
 * The alternative — the server driving playback on a timer — was never on the
 * table: it would need the server to know each listener's buffering state, and
 * it puts a tick on the wire every second for a feature whose state changes
 * every few minutes.
 *
 * ## What this service deliberately does not do
 *
 * - **It does not decide what plays next.** The host's player already owns a
 *   queue, a shuffle order and a repeat mode, and reimplementing those here
 *   would produce a second, disagreeing player (see the frontend player store).
 *   The host tells the room which song it moved to; the room follows.
 *
 * - **It does not check authorization.** {@see ListeningRoomPolicy}
 *   does, applied by the controllers, exactly as PlaylistCollaborationService
 *   relies on PlaylistPolicy. Every method here assumes its caller is allowed.
 *
 * - **It does not poll, and nothing it writes is written on a timer.** The only
 *   writes are the ones a human action produced.
 */
final class ListeningRoomService
{
    public function __construct(
        private readonly ListeningRoomRepository $rooms,
    ) {}

    /**
     * Opens a room with the host inside it.
     *
     * The queue and the opening playback state are optional but expected: the
     * button that starts a room is in the player, so the listener pressing it
     * almost always has something playing already, and a room that opened empty
     * while its creator was mid-song would make them start the album again.
     *
     * Nothing is broadcast here. There is nobody subscribed to a room that does
     * not exist yet, and the host learns the state from the response.
     *
     * @param  list<string>  $songIds
     */
    public function create(User $host, array $songIds = [], ?PlaybackCommand $initial = null): ListeningRoom
    {
        $room = $this->rooms->create([
            'room_code' => $this->freshCode(),
            'host_user_id' => $host->getKey(),
            'position_ms' => 0,
            'is_playing' => false,
            'playback_version' => 0,
        ]);

        $this->rooms->joinMember($room, $host, ListeningRole::Host);

        if ($songIds !== []) {
            $this->rooms->replaceQueue($room, $this->capped($songIds), $host);
        }

        if ($initial !== null) {
            $room = $this->rooms->writePlayback(
                $room,
                $initial->songId,
                max(0, $initial->positionMs),
                $initial->isPlaying,
                $this->measuredAt($initial->positionAtMs),
            );
        }

        return $this->rooms->withState($room);
    }

    /**
     * The live room a code points at.
     *
     * @throws DomainException 404 for an unknown code, 410 for a room that has ended.
     */
    public function findLive(string $code): ListeningRoom
    {
        $normalised = $this->normaliseCode($code);

        $room = $this->rooms->findLiveByCode($normalised);

        if ($room !== null) {
            return $room;
        }

        throw $this->rooms->findByCode($normalised) !== null
            ? DomainException::listeningRoomEnded()
            : DomainException::listeningRoomNotFound();
    }

    /**
     * The full room state, and a note that this member is still here.
     *
     * This doubles as the resync path: a client coming back from a dropped
     * connection asks for this and gets the authoritative playback state, the
     * queue and the member list in one answer, which is everything it needs to
     * stop guessing. It is a GET because it is a read — the `last_seen_at` stamp
     * is bookkeeping, not the point of the call.
     */
    public function state(ListeningRoom $room, User $viewer): ListeningRoom
    {
        $this->rooms->touchMember($room, $viewer, now());

        return $this->rooms->withState($room);
    }

    /**
     * Who this listener is on a room's broadcast channel, or null if they may
     * not be on it at all.
     *
     * This is the subscription gate, called from routes/channels.php. It is the
     * more important half of the authorization story than the policy is: the
     * policy stops a non-member *asking* the API about a room, but this is what
     * stops them receiving the room's traffic without asking — a private
     * listening session is otherwise readable by anyone who guesses six
     * characters and subscribes.
     *
     * Returning null denies the subscription. Returning the array both allows it
     * and becomes this member's presence entry, which is what the other clients
     * see in the room's online list.
     *
     * @return array<string, mixed>|null
     */
    public function channelMembership(string $roomId, User $user): ?array
    {
        $room = $this->rooms->findLiveById($roomId);

        if ($room === null) {
            return null;
        }

        $member = $this->rooms->member($room, $user);

        if ($member === null || ! $member->isActive()) {
            return null;
        }

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'avatar' => $user->avatar,
            'role' => $member->role->value,
            'is_host' => $room->isHost($user),
        ];
    }

    /**
     * The room as somebody holding the invite link sees it before joining.
     *
     * @see ListeningRoomPreviewResource for what is withheld.
     */
    public function preview(ListeningRoom $room): ListeningRoom
    {
        return $this->rooms->withPreview($room);
    }

    /**
     * Whether this listener is currently in the room.
     *
     * Present so the controllers can hand the policy a plain boolean, the same
     * arrangement `Playlist::isCollaborator()` gives PlaylistPolicy — a policy
     * that queried for its own facts could not be tested against unpersisted
     * models. "Currently" is the operative word: somebody who left has a
     * membership row and is not a member.
     */
    public function isMember(ListeningRoom $room, User $user): bool
    {
        $member = $this->rooms->member($room, $user);

        return $member !== null && $member->isActive();
    }

    /**
     * Adds a listener to a room, or re-admits one who had left.
     *
     * Idempotent: joining a room you are already in returns its state and tells
     * nobody, because a double-submitted join button announcing the same person
     * twice is how a member list ends up with duplicates in it.
     *
     * @throws DomainException When the room is at capacity.
     */
    public function join(ListeningRoom $room, User $user): ListeningRoom
    {
        $existing = $this->rooms->member($room, $user);
        $wasActive = $existing !== null && $existing->isActive();

        if (! $wasActive) {
            $max = (int) config('music.listening.max_members');

            if ($this->rooms->activeMemberCount($room) >= $max) {
                throw DomainException::listeningRoomFull($max);
            }
        }

        /*
         | Role follows the room, not the request. A host who reloads and re-joins
         | must come back as host; anyone else joining a room whose host slot is
         | taken is a participant regardless of what they were last time.
         */
        $this->rooms->joinMember($room, $user, $room->isHost($user)
            ? ListeningRole::Host
            : ListeningRole::Participant);

        if (! $wasActive) {
            $this->announceMembers($room, 'joined');
        }

        return $this->rooms->withState($room);
    }

    /**
     * Removes a listener, handing the room on or closing it as required.
     *
     * Three outcomes, in the order they are checked:
     *
     * 1. **A participant left.** Nothing else changes.
     * 2. **The host left and somebody is still listening.** The longest-present
     *    remaining member becomes host. Without this the room survives with
     *    nobody able to press anything — a room frozen mid-song, which is worse
     *    than it ending, because it looks like it still works.
     * 3. **The last member left.** The room is closed. Rooms exist for as long as
     *    someone is in them; an empty one is finished, and leaving it open would
     *    let a stale invite link drop somebody into silence.
     *
     * Idempotent for someone who is not a member, so a leave fired by both a
     * button and a page unload does not fail the second time.
     */
    public function leave(ListeningRoom $room, User $user): void
    {
        if (! $this->rooms->markLeft($room, $user, now())) {
            return;
        }

        $wasHost = $room->isHost($user);
        $successor = $wasHost ? $this->rooms->nextHostCandidate($room, $user) : null;

        if ($wasHost && $successor !== null) {
            $room = $this->rooms->assignHost($room, $successor);

            $this->announceMembers($room, 'host_changed');

            return;
        }

        /*
         | Nobody left to hand it to — or a participant leaving an already-empty
         | room, which the host-succession path can produce if two people leave in
         | the same instant. Either way the room is over, and the count is checked
         | rather than inferred from `$wasHost` so that neither ordering leaves a
         | live room with no members in it.
         */
        if ($this->rooms->activeMemberCount($room) === 0) {
            $this->rooms->close($room, now());

            return;
        }

        $this->announceMembers($room, 'left');
    }

    /**
     * Records the host's playback state and tells the room.
     *
     * The version bump inside `writePlayback` is what makes late delivery
     * harmless: every client keeps the highest version it has applied and
     * discards anything older, so a seek that overtakes a play on the wire
     * cannot rewind the room.
     *
     * `positionMs` is floored at zero and otherwise trusted. It comes from the
     * host's own audio element, which is the only thing that actually knows
     * where the audio is — second-guessing it here (clamping to a duration the
     * server holds, say, when the provider's clip is shorter than the metadata
     * claims) would fight the element and lose.
     */
    public function updatePlayback(ListeningRoom $room, User $host, PlaybackCommand $command): ListeningRoom
    {
        $now = now();

        $room = $this->rooms->writePlayback(
            $room,
            $command->songId,
            max(0, $command->positionMs),
            $command->isPlaying,
            $this->measuredAt($command->positionAtMs),
        );

        $this->rooms->touchMember($room, $host, $now);

        RoomPlaybackUpdated::dispatch($room, $command->reason);

        return $room;
    }

    /**
     * Replaces the room queue with the host player's own queue.
     *
     * Called whenever the host's queue changes — a new album, a track added, a
     * shuffle. A snapshot rather than a diff: see the repository method's note.
     *
     * @param  list<string>  $songIds
     */
    public function replaceQueue(ListeningRoom $room, User $host, array $songIds): ListeningRoom
    {
        $capped = $this->capped($songIds);

        $this->rooms->replaceQueue($room, $capped, $host);

        RoomQueueUpdated::dispatch($room, 'replaced', count($capped));

        return $this->rooms->withState($room);
    }

    /** @throws DomainException When the queue is at capacity. */
    public function addToQueue(ListeningRoom $room, User $host, string $songId): ListeningRoom
    {
        $max = (int) config('music.listening.max_queue');

        if ($this->rooms->queueSize($room) >= $max) {
            throw DomainException::listeningQueueFull($max);
        }

        $this->rooms->appendQueueItem($room, $songId, $host);

        RoomQueueUpdated::dispatch($room, 'added', $this->rooms->queueSize($room));

        return $this->rooms->withState($room);
    }

    /** @throws DomainException When the item is not in this room's queue. */
    public function removeFromQueue(ListeningRoom $room, string $itemId): ListeningRoom
    {
        if (! $this->rooms->removeQueueItem($room, $itemId)) {
            throw DomainException::listeningQueueItemNotFound();
        }

        RoomQueueUpdated::dispatch($room, 'removed', $this->rooms->queueSize($room));

        return $this->rooms->withState($room);
    }

    /**
     * Closes rooms whose members all disappeared without leaving.
     *
     * `leave()` handles every departure the app can see. This is for the ones it
     * cannot: a closed laptop, a killed tab, a phone that lost signal and never
     * came back. Those rooms would otherwise stay live forever, and a live room
     * is one an old invite link can still drop somebody into.
     *
     * Deliberately coarse and deliberately not a heartbeat. Tracking liveness
     * properly means every client pinging the server on a timer, which is the
     * polling this feature is built to avoid; the cost of being late here is a
     * room that lingers a few hours, which nobody notices.
     */
    public function prune(): int
    {
        $minutes = (int) config('music.listening.idle_expiry_minutes');

        return $this->rooms->closeStale(now()->subMinutes($minutes));
    }

    /**
     * Sends the current member list to the room.
     *
     * Always the whole list rather than "user X joined": a snapshot cannot be
     * applied out of order, and a client that missed one delta while reconnecting
     * would carry a wrong member list until it happened to refetch.
     */
    private function announceMembers(ListeningRoom $room, string $reason): void
    {
        RoomMembershipChanged::dispatch($room, $reason, $this->rooms->activeMembers($room));
    }

    /**
     * When a reported position was actually true.
     *
     * The client's own answer is preferred, because it is the only one that is
     * right: the server's arrival time is later than the measurement by however
     * long the request took, and storing the pair (position from then, timestamp
     * from now) biases every extrapolation short by exactly that delay. See
     * {@see PlaybackCommand::$positionAtMs} for the measurement that made this
     * worth fixing.
     *
     * Bounded rather than trusted. A value in the future would make the room
     * report a position it has not reached, and one from far in the past would
     * make it leap forward — so anything outside a narrow window around this
     * clock is discarded in favour of it. The window is asymmetric on purpose: a
     * request legitimately takes time to arrive (so a little in the past is
     * normal and expected), while arriving from the future never is, beyond the
     * few milliseconds of slop in the client's own estimate of this clock.
     */
    private function measuredAt(?int $clientMs): CarbonInterface
    {
        $now = now();

        if ($clientMs === null) {
            return $now;
        }

        /*
         | `setTimezone` is not decoration, it is the bug fix.
         |
         | Carbon 3 builds a timestamp in UTC regardless of the application's own
         | timezone, and this app runs on Asia/Kolkata. Both instants are stored
         | and compared as naive wall-clock strings — the column has no timezone —
         | so a UTC instant written beside `now()`'s local one lands five and a
         | half hours in the past. Read back, that made the room extrapolate a
         | position 19,800 seconds into a three-minute song: every participant
         | seeked past the end and sat in silence.
         |
         | Anchored to `$now`'s zone rather than to `config('app.timezone')` so
         | the two can never be answered differently.
         */
        $claimed = Carbon::createFromTimestampMs($clientMs)->setTimezone($now->getTimezone());

        if ($claimed->greaterThan($now->copy()->addMilliseconds(self::CLOCK_SKEW_TOLERANCE_MS))) {
            return $now;
        }

        if ($claimed->lessThan($now->copy()->subSeconds(self::MEASUREMENT_MAX_AGE_SECONDS))) {
            return $now;
        }

        /*
         | Clamped to the present, not left slightly ahead: a timestamp a few
         | milliseconds in the future is within the tolerance above and worth
         | accepting rather than discarding, but storing it as-is would have
         | `positionMsAt` subtract a negative elapsed time.
         */
        return $claimed->greaterThan($now) ? $now : $claimed;
    }

    /** How far ahead of this clock a client's timestamp may be before it is ignored. */
    private const CLOCK_SKEW_TOLERANCE_MS = 2_000;

    /** How stale a measurement may be before the server's own clock is used instead. */
    private const MEASUREMENT_MAX_AGE_SECONDS = 10;

    /**
     * A room code nobody is using.
     *
     * Bounded retries rather than a loop: a collision at this alphabet size is
     * already unlikely, and an unbounded retry turns the unlikely-but-possible
     * case of a saturated table into a request that never returns.
     *
     * @throws DomainException When every attempt collided.
     */
    private function freshCode(): string
    {
        $length = (int) config('music.listening.code_length');
        $alphabet = (string) config('music.listening.code_alphabet');

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = '';

            for ($i = 0; $i < $length; $i++) {
                // Not `Str::random` and not `array_rand`: this string is a
                // capability — holding it is what gets you into the room — so it
                // is minted from a cryptographic source, not a seeded one.
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            if (! $this->rooms->codeTaken($code)) {
                return $code;
            }
        }

        throw DomainException::listeningRoomCodeUnavailable();
    }

    /**
     * Room codes are matched case-insensitively.
     *
     * The code is read aloud and typed by hand as often as it is pasted, and
     * "abc123" not opening the room "ABC123" is a bug from the listener's side of
     * it. Stored uppercase, compared uppercase.
     */
    private function normaliseCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    /**
     * The queue truncated to the configured cap.
     *
     * Truncated rather than rejected: the host is mirroring a player queue they
     * did not build for this, and failing their "start listening together" press
     * because their queue is long would be answering the wrong question. What
     * plays first matters most, so the head is what is kept.
     *
     * Duplicates are *not* removed. A queue legitimately holds the same song
     * twice — an album with a reprise, a playlist someone added a track to twice
     * — and collapsing them here would silently reorder the host's own queue.
     *
     * @param  list<string>  $songIds
     * @return list<string>
     */
    private function capped(array $songIds): array
    {
        return array_slice(array_values($songIds), 0, (int) config('music.listening.max_queue'));
    }
}
