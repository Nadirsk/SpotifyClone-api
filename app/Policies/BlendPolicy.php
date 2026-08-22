<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Blend;
use App\Models\User;

/**
 * A Blend has no public or unlisted tier — unlike `PlaylistPolicy`, every
 * method here requires a real, non-null $user. 12_SCOPE_OF_WORK §23: "Users
 * must not be able to access another private Blend unless they are members."
 *
 * `$isMember` is computed by the caller (`Blend::isMember()`) rather than
 * queried here, same reasoning as `PlaylistPolicy`'s own doc — keeps this
 * testable against bare, unpersisted models.
 */
final class BlendPolicy
{
    public function view(User $user, Blend $blend, bool $isMember = false): bool
    {
        return $this->owns($user, $blend) || $isMember;
    }

    /** Rename. Creator only. */
    public function update(User $user, Blend $blend): bool
    {
        return $this->owns($user, $blend);
    }

    public function delete(User $user, Blend $blend): bool
    {
        return $this->owns($user, $blend);
    }

    /** Any member may trigger a manual refresh. */
    public function regenerate(User $user, Blend $blend, bool $isMember = false): bool
    {
        return $this->owns($user, $blend) || $isMember;
    }

    /** Any member may keep a copy — mirrors real Spotify's per-person "Save as Playlist". */
    public function save(User $user, Blend $blend, bool $isMember = false): bool
    {
        return $this->owns($user, $blend) || $isMember;
    }

    /** Only the creator may invite, per 12_SCOPE_OF_WORK §22's explicit creator/participant split. */
    public function invite(User $user, Blend $blend): bool
    {
        return $this->owns($user, $blend);
    }

    /** Only the creator may revoke a pending invitation or remove a member outright. */
    public function manageMembers(User $user, Blend $blend): bool
    {
        return $this->owns($user, $blend);
    }

    /** A member may remove themselves; the creator cannot "leave" — deleting the Blend is the equivalent action. */
    public function leave(User $user, Blend $blend, bool $isMember = false): bool
    {
        return $isMember && ! $this->owns($user, $blend);
    }

    private function owns(User $user, Blend $blend): bool
    {
        return $user->getKey() === $blend->created_by;
    }
}
