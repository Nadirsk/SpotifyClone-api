<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blend\InviteToBlendRequest;
use App\Http\Resources\BlendInvitationLinkResource;
use App\Http\Resources\BlendInvitationResource;
use App\Http\Resources\BlendResource;
use App\Contracts\Repositories\UserRepository;
use App\Models\User;
use App\Services\Blend\BlendInvitationService;
use App\Services\Blend\BlendService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Blend invitations — see BlendInvitationService's own doc for why these are
 * addressed to a specific account rather than a bare shareable link.
 */
final class BlendInvitationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BlendInvitationService $invitations,
        private readonly BlendService $blends,
        private readonly UserRepository $users,
    ) {}

    /** GET /blends/{blend}/invitations — every invitation ever sent for this Blend. Creator only. */
    public function index(string $blend): JsonResponse
    {
        $model = $this->blends->find($blend);

        Gate::authorize('invite', $model);

        return $this->respondSuccess(
            BlendInvitationLinkResource::collection($this->invitations->listFor($model)),
            'Invitations retrieved',
        );
    }

    /** POST /blends/{blend}/invitations */
    public function store(InviteToBlendRequest $request, string $blend): JsonResponse
    {
        $model = $this->blends->find($blend);

        Gate::authorize('invite', $model);

        $invitedUser = $this->users->findOrFail($request->validated()['user_id']);

        $invitation = $this->invitations->invite($model, $this->caller($request), $invitedUser);

        return $this->respondCreated([
            'token' => $invitation['token'],
            'expires_at' => $invitation['expires_at']?->toIso8601String(),
        ], 'Invitation sent');
    }

    /** DELETE /blends/{blend}/invitations/{invitation} — creator only. */
    public function revoke(string $blend, string $invitation): JsonResponse
    {
        $model = $this->blends->find($blend);

        Gate::authorize('manageMembers', $model);

        $this->invitations->revoke($this->invitations->findForBlend($model, $invitation));

        return $this->respondNoContent();
    }

    /**
     * GET /blends/invitations/{token} — the pre-login preview a Blend
     * invitation's link opens to. No auth middleware: this is what lets a
     * signed-out recipient see who invited them, and to what, before being
     * asked to log in as the account it was actually sent to.
     */
    public function showByToken(string $token): JsonResponse
    {
        $invitation = $this->invitations->findByToken($token);

        return $this->respondSuccess(new BlendInvitationResource($invitation), 'Invitation retrieved');
    }

    /** POST /blends/invitations/{token}/accept */
    public function accept(Request $request, string $token): JsonResponse
    {
        $blend = $this->invitations->accept($token, $this->caller($request));

        return $this->respondSuccess(new BlendResource($blend), 'Joined Blend');
    }

    /** POST /blends/invitations/{token}/decline */
    public function decline(Request $request, string $token): JsonResponse
    {
        $this->invitations->decline($token, $this->caller($request));

        return $this->respondNoContent();
    }

    /** @return User Guaranteed by the route's auth:sanctum middleware. */
    private function caller(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
