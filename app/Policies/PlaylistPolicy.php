<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlaylistVisibility;
use App\Models\Playlist;
use App\Models\User;

final class PlaylistPolicy
{
    /**
     * Guests are allowed through here, hence the nullable User: a public or
     * unlisted playlist is readable without an account.
     *
     * Unlisted is treated as viewable, not as private — it means "reachable by
     * anyone holding the link". Keeping it out of browse results is the
     * listing query's job (Playlist::scopeVisibleTo), not this policy's.
     *
     * A private playlist is also viewable by an active collaborator — the
     * invite is what makes it "restricted to owner + invitees", not "owner
     * only". `$isCollaborator` is computed by the caller (`Playlist::
     * isCollaborator()`) rather than here, so this policy keeps comparing
     * plain scalars and stays testable against bare, unpersisted models
     * (see PlaylistPolicyTest).
     */
    public function view(?User $user, Playlist $playlist, bool $isCollaborator = false): bool
    {
        if ($playlist->visibility !== PlaylistVisibility::Private) {
            return true;
        }

        return $this->owns($user, $playlist) || $isCollaborator;
    }

    public function update(User $user, Playlist $playlist): bool
    {
        return $this->owns($user, $playlist);
    }

    public function delete(User $user, Playlist $playlist): bool
    {
        return $this->owns($user, $playlist);
    }

    /**
     * An active collaborator may add songs only while the playlist is
     * currently marked collaborative — turning that off pauses their edit
     * rights without removing them from `playlist_collaborators` (a separate,
     * explicit action: `removeCollaborator()`/`leave()`).
     */
    public function addSong(User $user, Playlist $playlist, bool $isCollaborator = false): bool
    {
        return $this->owns($user, $playlist) || ($playlist->is_collaborative && $isCollaborator);
    }

    public function removeSong(User $user, Playlist $playlist, bool $isCollaborator = false): bool
    {
        return $this->owns($user, $playlist) || ($playlist->is_collaborative && $isCollaborator);
    }

    /** Only the owner may generate, view, or revoke the invite link. */
    public function inviteCollaborators(User $user, Playlist $playlist): bool
    {
        return $this->owns($user, $playlist);
    }

    /** Only the owner may remove someone else's collaborator access. */
    public function removeCollaborator(User $user, Playlist $playlist): bool
    {
        return $this->owns($user, $playlist);
    }

    /**
     * A collaborator may remove themselves; the owner cannot "leave" their
     * own playlist — deleting it is the only equivalent action.
     */
    public function leave(User $user, Playlist $playlist, bool $isCollaborator = false): bool
    {
        return $isCollaborator && ! $this->owns($user, $playlist);
    }

    private function owns(?User $user, Playlist $playlist): bool
    {
        return $user !== null && $user->getKey() === $playlist->user_id;
    }
}
