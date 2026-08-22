<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Blend;
use App\Models\BlendInvitation;
use App\Models\BlendMember;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface BlendRepository
{
    /**
     * Blends $user created or was invited into and accepted — never a Blend
     * they are merely a pending invitee on.
     *
     * @return LengthAwarePaginator<int, Blend>
     */
    public function paginateForUser(User $user, int $page, int $limit): LengthAwarePaginator;

    /** @throws ModelNotFoundException */
    public function findOrFail(string $id): Blend;

    /**
     * Creates the Blend and seats its creator as the first member, in one
     * transaction — a Blend with no members at all is not a state anything
     * else in this feature is written to expect.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $creator, array $attributes): Blend;

    /** @param  array<string, mixed>  $attributes */
    public function update(Blend $blend, array $attributes): Blend;

    public function delete(Blend $blend): void;

    /**
     * Members of $blend, with their user profile loaded, joined-first order.
     *
     * @return Collection<int, BlendMember>
     */
    public function members(Blend $blend): Collection;

    /**
     * Seats $user on $blend. Returns false when they already are a member —
     * accepting an invitation twice, or the creator's own initial seat being
     * requested again, is a no-op rather than a duplicate row.
     */
    public function addMember(Blend $blend, User $user, string $role = 'member'): bool;

    /** Returns false when $user was not a member. */
    public function removeMember(Blend $blend, User $user): bool;

    /** The invitation, if any, that $blend currently has open for $invitedUser — active or not. */
    public function findInvitation(Blend $blend, User $invitedUser): ?BlendInvitation;

    /**
     * Creates the invitation, or replaces the existing one for the same
     * (blend, invited user) pair — re-inviting someone resets a declined or
     * expired invitation back to pending rather than piling up rows.
     */
    public function putInvitation(
        Blend $blend,
        User $invitedBy,
        User $invitedUser,
        string $token,
        ?\DateTimeInterface $expiresAt,
    ): BlendInvitation;

    /** Null when no invitation exists for that token. Loaded with its blend and both users. */
    public function findInvitationByToken(string $token): ?BlendInvitation;

    public function markInvitationResponded(BlendInvitation $invitation, string $status): void;

    public function revokeInvitation(BlendInvitation $invitation): void;

    /**
     * Every song currently in $blend, in rank order, with catalog relations
     * loaded — what `BlendResource`'s `tracks` serialises.
     *
     * @return Collection<int, Song>
     */
    public function songs(Blend $blend): Collection;

    /**
     * Atomically replaces $blend's entire tracklist and stamps
     * `last_generated_at` — a regeneration is a full replace, not a merge, so
     * a song dropped by the new ranking actually leaves the Blend.
     *
     * @param  list<array{song_id: string, score: float, reason: string, attributed_user_id: ?string, position: int}>  $scoredSongs
     */
    public function replaceSongs(Blend $blend, array $scoredSongs, ?int $matchScore): void;
}
