<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistCollaboratorResource;
use App\Http\Resources\PlaylistInvitationResource;
use App\Http\Resources\PlaylistResource;
use App\Models\User;
use App\Services\Library\PlaylistCollaborationService;
use App\Services\Library\PlaylistService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Spotify-style "Invite collaborators" — one shareable, revocable link per
 * playlist. See PlaylistCollaborationService's doc for the join model.
 */
final class PlaylistCollaboratorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PlaylistCollaborationService $collaboration,
        private readonly PlaylistService $playlists,
    ) {}

    /** GET /playlists/{playlist}/collaborators */
    public function index(Request $request, string $playlist): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('view', [$model, $model->isCollaborator($this->viewer($request))]);

        return $this->respondSuccess(
            PlaylistCollaboratorResource::collection($this->collaboration->listCollaborators($model)),
            'Collaborators retrieved',
        );
    }

    /** DELETE /playlists/{playlist}/collaborators/{user} */
    public function destroy(string $playlist, string $user): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('removeCollaborator', $model);

        $this->collaboration->removeCollaborator($model, $user);

        return $this->respondNoContent();
    }

    /** POST /playlists/{playlist}/leave */
    public function leave(Request $request, string $playlist): JsonResponse
    {
        $model = $this->playlists->find($playlist);
        $caller = $this->caller($request);

        Gate::authorize('leave', [$model, $model->isCollaborator($caller)]);

        $this->collaboration->leave($model, $caller);

        return $this->respondNoContent();
    }

    /** POST /playlists/{playlist}/invitations — creates or regenerates the invite link. */
    public function invite(string $playlist): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('inviteCollaborators', $model);

        $invitation = $this->collaboration->createInvitation($model, $model->owner);

        return $this->respondCreated([
            'token' => $invitation['token'],
            'expires_at' => $invitation['expires_at']->toIso8601String(),
        ], 'Invite link created');
    }

    /**
     * GET /playlists/{playlist}/invitations — the current active link, if
     * any, re-displayed in full (same URL every time), matching real
     * Spotify: reopening "Invite collaborators" shows the link you already
     * have rather than forcing a new one.
     */
    public function showInvitation(string $playlist): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('inviteCollaborators', $model);

        $invitation = $this->collaboration->currentInvitation($model);

        return $this->respondSuccess(
            $invitation === null ? null : [
                'token' => $invitation->token,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
            'Invite link retrieved',
        );
    }

    /** DELETE /playlists/{playlist}/invitations */
    public function revokeInvitation(string $playlist): JsonResponse
    {
        $model = $this->playlists->find($playlist);

        Gate::authorize('inviteCollaborators', $model);

        $this->collaboration->revokeInvitation($model);

        return $this->respondNoContent();
    }

    /**
     * GET /playlists/invitations/{token}
     *
     * No auth middleware on purpose: this is the pre-login preview a shared
     * link opens to, so a guest can see what they are being invited to before
     * being asked to sign in.
     */
    public function showByToken(string $token): JsonResponse
    {
        $invitation = $this->collaboration->findInvitationByToken($token);

        return $this->respondSuccess(new PlaylistInvitationResource($invitation), 'Invitation retrieved');
    }

    /** POST /playlists/invitations/{token}/accept */
    public function accept(Request $request, string $token): JsonResponse
    {
        $playlist = $this->collaboration->acceptInvitation($token, $this->caller($request));

        return $this->respondSuccess(new PlaylistResource($playlist), 'Joined playlist');
    }

    private function viewer(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /** @return User Guaranteed by the route's auth:sanctum middleware. */
    private function caller(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
