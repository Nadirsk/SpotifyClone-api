<?php

declare(strict_types=1);

namespace App\Services\Blend;

use App\Contracts\Repositories\BlendRepository;
use App\Enums\BlendInvitationStatus;
use App\Enums\BlendMemberRole;
use App\Exceptions\DomainException;
use App\Models\Blend;
use App\Models\BlendInvitation;
use App\Models\User;
use App\Notifications\BlendInvitationReceived;
use App\Notifications\BlendMemberJoined;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Blend invitations — addressed to a specific account, unlike
 * `PlaylistCollaborationService`'s one shareable link. See the
 * `blend_invitations` migration's own doc for why. Authorization (creator-only
 * invite/revoke/remove) lives in {@see \App\Policies\BlendPolicy} and is
 * applied by the controller; this service assumes the caller is already
 * authorized.
 */
final class BlendInvitationService
{
    public function __construct(
        private readonly BlendRepository $blends,
        private readonly BlendGenerationService $generation,
    ) {}

    /**
     * @return array{token: string, expires_at: Carbon}
     *
     * @throws DomainException When inviting self, an existing member, or the Blend is full.
     */
    public function invite(Blend $blend, User $inviter, User $invitedUser): array
    {
        if ($inviter->getKey() === $invitedUser->getKey()) {
            throw DomainException::cannotInviteSelfToBlend();
        }

        if ($blend->isMember($invitedUser)) {
            throw DomainException::alreadyBlendMember();
        }

        $max = (int) config('music.blends.max_members');
        $currentCount = $blend->relationLoaded('members') ? $blend->members->count() : $blend->members()->count();

        if ($currentCount >= $max) {
            throw DomainException::blendFull($max);
        }

        $token = Str::random(40);
        $expiryDays = (int) config('music.blends.invite_expiry_days');

        $invitation = $this->blends->putInvitation($blend, $inviter, $invitedUser, $token, now()->addDays($expiryDays));

        $invitedUser->notify(new BlendInvitationReceived($blend, $inviter, $token));

        return ['token' => $invitation->token, 'expires_at' => $invitation->expires_at];
    }

    /**
     * The invitation a token points to, for the pre-login "you've been
     * invited" preview. Active only — an expired/declined/revoked token
     * answers the same "invalid" error the accept endpoint would give it,
     * rather than a preview of something that can no longer be acted on.
     *
     * @throws DomainException When the token is unknown, expired, declined, or revoked.
     */
    public function findByToken(string $token): BlendInvitation
    {
        $invitation = $this->blends->findInvitationByToken($token);

        if ($invitation === null || ! $invitation->isActive()) {
            throw DomainException::blendInvitationInvalid();
        }

        return $invitation;
    }

    /**
     * Seats $user, triggers generation now that the Blend has enough members,
     * and notifies every member already on it — not only the creator, so this
     * keeps working unmodified once a Blend grows past two people (§30).
     *
     * @throws DomainException When the token is invalid, or addressed to a different account.
     */
    public function accept(string $token, User $user): Blend
    {
        $invitation = $this->findByToken($token);

        if ($invitation->invited_user_id !== $user->getKey()) {
            throw DomainException::blendInvitationNotForYou();
        }

        $blend = $invitation->blend;
        $this->blends->markInvitationResponded($invitation, BlendInvitationStatus::Accepted->value);

        if ($this->blends->addMember($blend, $user, BlendMemberRole::Member->value)) {
            $blend->load(['creator', 'members.user']);

            /*
             | "Aaibuzz + Vishal" — only while the creator never typed a name
             | of their own (BlendService::rename() flips title_is_default
             | off), and only at the moment the *second* member arrives: a
             | third member joining an already-named Blend should not silently
             | rewrite a name the group is already using.
             */
            if ($blend->title_is_default && $blend->members->count() === 2) {
                $creatorName = $blend->creator?->name ?? 'Someone';

                $this->blends->update($blend, ['title' => "{$creatorName} + {$user->name}"]);
            }

            $this->generation->generate($blend);

            foreach ($blend->members as $member) {
                if ($member->user_id !== $user->getKey()) {
                    $member->user?->notify(new BlendMemberJoined($blend, $user));
                }
            }
        }

        return $blend->refresh()->load([
            'creator', 'members.user', 'songs.artist', 'songs.album', 'songs.genre', 'songs.language',
        ]);
    }

    /** @throws DomainException When the token is invalid, or addressed to a different account. */
    public function decline(string $token, User $user): void
    {
        $invitation = $this->findByToken($token);

        if ($invitation->invited_user_id !== $user->getKey()) {
            throw DomainException::blendInvitationNotForYou();
        }

        $this->blends->markInvitationResponded($invitation, BlendInvitationStatus::Declined->value);
    }

    public function revoke(BlendInvitation $invitation): void
    {
        $this->blends->revokeInvitation($invitation);
    }

    /**
     * One invitation, scoped to $blend — used by the creator's own
     * "Manage Blend" view, never by the recipient (they only ever reach an
     * invitation through its token, via `findByToken()`).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findForBlend(Blend $blend, string $invitationId): BlendInvitation
    {
        /** @var BlendInvitation */
        return $blend->invitations()->findOrFail($invitationId);
    }

    /**
     * Every invitation ever sent for $blend, newest first — the creator's
     * "Manage Blend" list.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BlendInvitation>
     */
    public function listFor(Blend $blend): \Illuminate\Database\Eloquent\Collection
    {
        return $blend->invitations()->with('invitedUser')->latest()->get();
    }
}
