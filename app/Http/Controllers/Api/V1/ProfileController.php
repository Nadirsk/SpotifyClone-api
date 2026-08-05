<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\ProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 05_API_SPECIFICATION §14.
 *
 * There is no `{id}` on any of these routes by design: the subject is always
 * the token's own user.
 */
final class ProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProfileService $profile,
    ) {}

    /** GET /profile */
    public function show(Request $request): JsonResponse
    {
        return $this->respondSuccess(
            new UserResource($this->profile->show($this->user($request))),
            'Profile retrieved',
        );
    }

    /** PUT /profile */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        return $this->respondSuccess(
            new UserResource($this->profile->update(
                $this->user($request),
                $request->attributesToUpdate(),
            )),
            'Profile updated',
        );
    }

    /** PUT /profile/avatar */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        return $this->respondSuccess(
            new UserResource($this->profile->updateAvatar(
                $this->user($request),
                $request->avatar(),
            )),
            'Avatar updated',
        );
    }

    /** DELETE /profile — soft-deletes the account and revokes its tokens. */
    public function destroy(Request $request): JsonResponse
    {
        $this->profile->delete($this->user($request));

        return $this->respondNoContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user Guaranteed by the route's auth:sanctum middleware. */
        $user = $request->user();

        return $user;
    }
}
