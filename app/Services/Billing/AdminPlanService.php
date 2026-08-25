<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Write side of the `plans` table, for the admin panel. {@see PlanCatalog}
 * stays the read side every gate and the public `GET /plans` go through;
 * this only ever touches the same four rows an admin edits — see the
 * `plans` migration for why nothing here deletes a row, and
 * {@see StorePlanRequest} for why "add" can only ever fill in a plan
 * identity the app's enum already knows about, never invent one.
 */
final class AdminPlanService
{
    /**
     * Keys a real code path reads by name — `max_audio_quality` because
     * {@see PlanCatalog::maxQuality()} indexes it without a fallback, the
     * rest because removing them would silently turn off a real product gate
     * (ad-free, downloads, …) on every plan at once rather than just failing
     * to render. Anything else was only ever a cosmetic admin-panel toggle
     * and is safe to delete outright — a missing boolean key reads as
     * `false` via {@see PlanCatalog::entitles()}.
     *
     * @var list<string>
     */
    public const PROTECTED_ENTITLEMENT_KEYS = [
        'max_audio_quality',
        'on_demand_playback',
        'download',
        'offline_listening',
        'playlist_with_episodes',
        'ad_free',
        'unlimited_skips',
        'playlist_mixing',
        'listen_together',
    ];

    /**
     * @return Collection<int, Plan>
     */
    public function all(): Collection
    {
        return Plan::query()->get();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(string $plan): Plan
    {
        /** @var Plan */
        return Plan::query()->findOrFail($plan);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Plan
    {
        return Plan::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Plan $plan, array $data): Plan
    {
        $plan->update($data);

        return $plan;
    }

    /**
     * Drops a custom entitlement key from every plan that has it — the
     * admin-panel counterpart to "+ Add Entitlement". Never call this with a
     * key from {@see PROTECTED_ENTITLEMENT_KEYS}; the controller rejects
     * those before this runs.
     *
     * @return int How many plans actually had the key.
     */
    public function removeEntitlementKey(string $key): int
    {
        $affected = 0;

        foreach (Plan::query()->get() as $plan) {
            $entitlements = $plan->entitlements;

            if (! array_key_exists($key, $entitlements)) {
                continue;
            }

            unset($entitlements[$key]);
            $plan->update(['entitlements' => $entitlements]);
            $affected++;
        }

        return $affected;
    }
}
