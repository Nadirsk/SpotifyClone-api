<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\UpdateUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\AdminUserService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Account management for the admin panel — every route sits behind
 * ['auth:sanctum', 'admin']. Reuses the public UserResource: this is the
 * one place its email/phone fields are meant to be seen by someone other
 * than the account's own owner.
 */
final class AdminUserController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminUserService $users,
    ) {}

    /**
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');

        return $this->respondPaginated(
            $this->users->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
            ),
            UserResource::class,
        );
    }

    /**
     * GET /api/v1/admin/users/{id}
     */
    public function show(string $id): JsonResponse
    {
        return $this->respondSuccess(new UserResource($this->users->find($id)));
    }

    /**
     * PUT /api/v1/admin/users/{id}/role
     */
    public function updateRole(UpdateUserRoleRequest $request, string $id): JsonResponse
    {
        $user = $this->users->find($id);

        $this->guardAgainstSelf($request, $user, 'You cannot change your own role.');

        return $this->respondSuccess(
            new UserResource($this->users->updateRole($user, $request->validated('role'))),
            'Role updated',
        );
    }

    /**
     * DELETE /api/v1/admin/users/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->users->find($id);

        $this->guardAgainstSelf($request, $user, 'You cannot delete your own account from here.');

        $this->users->delete($user);

        return $this->respondNoContent();
    }

    /**
     * An admin acting on their own row here — demoting themselves, deleting
     * themselves — is a self-lockout waiting to happen, not a real use case
     * this screen needs to support.
     *
     * @throws ValidationException
     */
    private function guardAgainstSelf(Request $request, User $target, string $message): void
    {
        /** @var User $actor Guaranteed by the route's auth:sanctum middleware. */
        $actor = $request->user();

        if ($actor->getKey() === $target->getKey()) {
            throw ValidationException::withMessages(['id' => [$message]]);
        }
    }
}
