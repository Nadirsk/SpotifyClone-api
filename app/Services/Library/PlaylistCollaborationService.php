<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Contracts\Repositories\PlaylistRepository;
use App\Contracts\Repositories\UserRepository;
use App\Exceptions\DomainException;
use App\Models\Playlist;
use App\Models\PlaylistCollaborator;
use App\Models\PlaylistInvitation;
use App\Models\User;
use App\Notifications\PlaylistCollaboratorJoined;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Spotify-style "Invite collaborators": one shareable, revocable link per
 * playlist rather than a per-person invite — see `PlaylistInvitation::
 * isActive()`'s doc. Authorization (owner-only invite/remove, collaborator-only
 * leave) lives in {@see \App\Policies\PlaylistPolicy} and is applied by the
 * controller; this service assumes the caller is already authorized.
 */
final class PlaylistCollaborationService
{
    public function __construct(
        private readonly PlaylistRepository $playlists,
        private readonly UserRepository $users,
    ) {}

    /** @return Collection<int, PlaylistCollaborator> */
    public function listCollaborators(Playlist $playlist): Collection
    {
        return $this->playlists->collaborators($playlist);
    }

    /** The playlist's current invite link, if it is still usable. */
    public function currentInvitation(Playlist $playlist): ?PlaylistInvitation
    {
        $invitation = $this->playlists->findInvitation($playlist);

        return $invitation !== null && $invitation->isActive() ? $invitation : null;
    }

    /**
     * Creates the playlist's invite link, or regenerates it — a previous link
     * stops working the moment a new one is issued, since only one row per
     * playlist is ever kept.
     *
     * @return array{token: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function createInvitation(Playlist $playlist, User $owner): array
    {
        $token = Str::random(40);
        $expiryDays = (int) config('music.playlists.invite_expiry_days');

        $invitation = $this->playlists->putInvitation(
            $playlist,
            $owner,
            $token,
            now()->addDays($expiryDays),
        );

        return ['token' => $token, 'expires_at' => $invitation->expires_at];
    }

    public function revokeInvitation(Playlist $playlist): void
    {
        $this->playlists->revokeInvitation($playlist);
    }

    /**
     * The playlist a token points to, for the pre-login "you've been invited"
     * preview page. Deliberately returns the bare model — the caller decides
     * how much of it is safe to expose before the viewer has joined.
     *
     * @throws DomainException When the token is unknown, expired, or revoked.
     */
    public function findInvitationByToken(string $token): PlaylistInvitation
    {
        $invitation = $this->playlists->findActiveInvitationByToken($token);

        if ($invitation === null) {
            throw DomainException::invitationInvalid();
        }

        return $invitation;
    }

    /**
     * Joining is idempotent: accepting a link twice (a re-clicked share, a
     * double submit) just lands the caller on the playlist they are already
     * part of rather than erroring.
     *
     * @throws DomainException When the token is invalid, or the caller already owns the playlist.
     */
    public function acceptInvitation(string $token, User $user): Playlist
    {
        $invitation = $this->findInvitationByToken($token);
        $playlist = $invitation->playlist;

        if ($playlist->user_id === $user->getKey()) {
            throw DomainException::cannotJoinOwnPlaylist();
        }

        /*
         | Only tell the owner when someone actually joined. `addCollaborator()`
         | returns false for a user who is already on the playlist, and opening
         | the invite link twice must not send the notification twice.
         */
        if ($this->playlists->addCollaborator($playlist, $user)) {
            $playlist->loadMissing('owner')->owner?->notify(
                new PlaylistCollaboratorJoined($playlist, $user),
            );
        }

        return $playlist->refresh()->loadMissing('owner');
    }

    /** @throws DomainException When $userId is not currently a collaborator. */
    public function removeCollaborator(Playlist $playlist, string $userId): void
    {
        $user = $this->users->findOrFail($userId);

        if (! $this->playlists->removeCollaborator($playlist, $user)) {
            throw DomainException::notACollaborator();
        }
    }

    /** @throws DomainException When $user is not currently a collaborator. */
    public function leave(Playlist $playlist, User $user): void
    {
        if (! $this->playlists->removeCollaborator($playlist, $user)) {
            throw DomainException::notACollaborator();
        }
    }
}
