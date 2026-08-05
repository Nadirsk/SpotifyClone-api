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
     */
    public function view(?User $user, Playlist $playlist): bool
    {
        if ($playlist->visibility !== PlaylistVisibility::Private) {
            return true;
        }

        return $this->owns($user, $playlist);
    }

    public function update(User $user, Playlist $playlist): bool
    {
        return $this->owns($user, $playlist);
    }

    public function delete(User $user, Playlist $playlist): bool
    {
        return $this->owns($user, $playlist);
    }

    public function addSong(User $user, Playlist $playlist): bool
    {
        return $this->owns($user, $playlist);
    }

    public function removeSong(User $user, Playlist $playlist): bool
    {
        return $this->owns($user, $playlist);
    }

    private function owns(?User $user, Playlist $playlist): bool
    {
        return $user !== null && $user->getKey() === $playlist->user_id;
    }
}
