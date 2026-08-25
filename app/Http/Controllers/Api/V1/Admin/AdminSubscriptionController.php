<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subscription\UpdateSubscriptionStatusRequest;
use App\Http\Resources\Admin\AdminSubscriptionResource;
use App\Services\Billing\AdminSubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Subscription oversight for the admin panel: list every purchase, and
 * override its status for support cases. No create/full-update — see
 * AdminSubscriptionService's docblock for why. Every route sits behind
 * ['auth:sanctum', 'admin'].
 */
final class AdminSubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminSubscriptionService $subscriptions,
    ) {}

    /**
     * GET /api/v1/admin/subscriptions
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('q');
        $status = $request->query('status');

        return $this->respondPaginated(
            $this->subscriptions->paginate(
                max(1, (int) $request->query('page', 1)),
                max(1, (int) $request->query('limit', 20)),
                is_string($search) ? $search : null,
                is_string($status) ? $status : null,
            ),
            AdminSubscriptionResource::class,
        );
    }

    /**
     * PUT /api/v1/admin/subscriptions/{id}/status
     */
    public function updateStatus(UpdateSubscriptionStatusRequest $request, string $id): JsonResponse
    {
        $subscription = $this->subscriptions->find($id);

        return $this->respondSuccess(
            new AdminSubscriptionResource($this->subscriptions->updateStatus($subscription, $request->validated('status'))),
            'Subscription updated',
        );
    }
}
