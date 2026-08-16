<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Playlist;
use App\Models\PlaylistCollaborator;
use App\Models\PlaylistInvitation;
use App\Models\Song;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface PlaylistRepository
{
    /**
     * Playlists $viewer is allowed to list: their own plus everyone's public
     * ones. Unlisted playlists are excluded — they are link-only by design.
     *
     * @return LengthAwarePaginator<int, Playlist>
     */
    public function paginateVisibleTo(?User $viewer, int $page, int $limit): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, Playlist> */
    public function paginateForOwner(User $owner, int $page, int $limit): LengthAwarePaginator;

    /** @throws ModelNotFoundException */
    public function findOrFail(string $id): Playlist;

    /** @param  array<string, mixed>  $attributes */
    public function create(User $owner, array $attributes): Playlist;

    /** @param  array<string, mixed>  $attributes */
    public function update(Playlist $playlist, array $attributes): Playlist;

    public function delete(Playlist $playlist): void;

    /**
     * Appends $song at the end. Returns false when the song is already present,
     * so the caller can answer 409 rather than silently doing nothing.
     */
    public function addSong(Playlist $playlist, Song $song): bool;

    /** Returns false when the song was not in the playlist. */
    public function removeSong(Playlist $playlist, Song $song): bool;

    /**
     * Recomputes tracks_count and total_duration from playlist_tracks.
     */
    public function refreshCounters(Playlist $playlist): void;

    /**
     * Collaborators on $playlist, with their user profile loaded.
     *
     * @return Collection<int, PlaylistCollaborator>
     */
    public function collaborators(Playlist $playlist): Collection;

    /**
     * Adds $user as a collaborator. Returns false when they already are one
     * (accepting an invite twice is a no-op, not an error) or when $user owns
     * the playlist.
     */
    public function addCollaborator(Playlist $playlist, User $user): bool;

    /** Returns false when $user was not a collaborator. */
    public function removeCollaborator(Playlist $playlist, User $user): bool;

    /** The playlist's current invite link, active or not. Null if none was ever created. */
    public function findInvitation(Playlist $playlist): ?PlaylistInvitation;

    /**
     * Creates the playlist's invite link, or replaces the existing one —
     * there is only ever one per playlist, matching a single shareable
     * "Invite collaborators" link rather than one per invitee.
     */
    public function putInvitation(Playlist $playlist, User $invitedBy, string $token, ?\DateTimeInterface $expiresAt): PlaylistInvitation;

    /** Null when no invite exists, has expired, or was revoked. */
    public function findActiveInvitationByToken(string $token): ?PlaylistInvitation;

    /** Returns false when the playlist had no invite link. */
    public function revokeInvitation(Playlist $playlist): bool;
}
