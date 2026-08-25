<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Plan\StorePlanRequest;
use App\Http\Requests\Admin\Plan\UpdatePlanRequest;
use App\Http\Resources\Admin\AdminPlanResource;
use App\Services\Billing\AdminPlanService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Admin management of subscription plans — what each tier costs and
 * unlocks, editable without a deploy. No destroy: a plan row backs live
 * entitlement checks and existing subscriptions, so removing one is not a
 * safe admin-panel action. Every route sits behind ['auth:sanctum', 'admin'].
 */
final class AdminPlanController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminPlanService $plans,
    ) {}

    /**
     * GET /api/v1/admin/plans
     */
    public function index(): JsonResponse
    {
        return $this->respondSuccess(AdminPlanResource::collection($this->plans->all()));
    }

    /**
     * POST /api/v1/admin/plans
     */
    public function store(StorePlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['entitlements'] = $request->entitlementsPayload();

        $plan = $this->plans->create($data);

        return $this->respondCreated(new AdminPlanResource($plan), 'Plan created');
    }

    /**
     * PUT /api/v1/admin/plans/{plan}
     */
    public function update(UpdatePlanRequest $request, string $plan): JsonResponse
    {
        $model = $this->plans->find($plan);

        $data = $request->validated();
        if (($entitlements = $request->entitlementsPayload()) !== null) {
            $data['entitlements'] = $entitlements;
        }

        return $this->respondSuccess(
            new AdminPlanResource($this->plans->update($model, $data)),
            'Plan updated',
        );
    }

    /**
     * DELETE /api/v1/admin/plans/entitlements/{key}
     *
     * Drops a custom entitlement key from every plan. Refuses the fixed set
     * a real gate or code path depends on — those are edited, never removed.
     */
    public function destroyEntitlement(string $key): JsonResponse
    {
        if (in_array($key, AdminPlanService::PROTECTED_ENTITLEMENT_KEYS, true)) {
            throw ValidationException::withMessages([
                'key' => ["\"{$key}\" is a built-in entitlement and can't be removed."],
            ]);
        }

        $affected = $this->plans->removeEntitlementKey($key);

        return $this->respondSuccess(['affected_plans' => $affected], 'Entitlement removed');
    }
}
