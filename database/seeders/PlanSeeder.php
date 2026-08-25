<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AudioQuality;
use App\Enums\SubscriptionPlan;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The four rows `plans` starts with — exactly the values that used to live
 * in config/plans.php, so moving pricing into the database changes nothing
 * a listener sees. Idempotent on `plan` (`updateOrCreate`), so re-running
 * this after an admin has edited a row would stomp their change — see
 * PlanCatalog's docblock for why that trade-off is acceptable here: this
 * seeder exists to bootstrap the table once, not to keep re-syncing it.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            SubscriptionPlan::Free->value => [
                'name' => 'Free',
                'tagline' => 'Listen with limits.',
                'accounts' => 1,
                'max_sessions' => null,
                'reference_price_inr' => null,
                'reference_price_usd' => null,
                'entitlements' => [
                    'on_demand_playback' => false,
                    'max_audio_quality' => AudioQuality::Normal->value,
                    'download' => false,
                    'offline_listening' => false,
                    'playlist_with_episodes' => false,
                    'ad_free' => false,
                    'unlimited_skips' => false,
                    'playlist_mixing' => false,
                    'listen_together' => false,
                ],
            ],
            SubscriptionPlan::Standard->value => [
                'name' => 'Standard',
                'tagline' => 'Listen without limits. Cancel anytime.',
                'accounts' => 1,
                'max_sessions' => 1,
                'reference_price_inr' => 13900,
                'reference_price_usd' => 1199,
                'entitlements' => [
                    'on_demand_playback' => true,
                    'max_audio_quality' => AudioQuality::VeryHigh->value,
                    'download' => true,
                    'offline_listening' => true,
                    'playlist_with_episodes' => true,
                    'ad_free' => true,
                    'unlimited_skips' => true,
                    'playlist_mixing' => false,
                    'listen_together' => false,
                ],
            ],
            SubscriptionPlan::Platinum->value => [
                'name' => 'Platinum',
                'tagline' => 'Everything in Standard, at the highest fidelity.',
                'accounts' => 3,
                'max_sessions' => 3,
                'reference_price_inr' => 29900,
                'reference_price_usd' => 1999,
                'entitlements' => [
                    'on_demand_playback' => true,
                    'max_audio_quality' => AudioQuality::Lossless->value,
                    'download' => true,
                    'offline_listening' => true,
                    'playlist_with_episodes' => true,
                    'ad_free' => true,
                    'unlimited_skips' => true,
                    'playlist_mixing' => true,
                    'listen_together' => true,
                ],
            ],
            SubscriptionPlan::Golden->value => [
                'name' => 'Golden',
                'tagline' => 'Standard, for Golden members.',
                'accounts' => 1,
                'max_sessions' => 1,
                'reference_price_inr' => 6900,
                'reference_price_usd' => 599,
                'entitlements' => [
                    'on_demand_playback' => true,
                    'max_audio_quality' => AudioQuality::VeryHigh->value,
                    'download' => true,
                    'offline_listening' => true,
                    'playlist_with_episodes' => true,
                    'ad_free' => true,
                    'unlimited_skips' => true,
                    'playlist_mixing' => false,
                    'listen_together' => false,
                ],
            ],
        ];

        foreach ($rows as $plan => $attributes) {
            Plan::query()->updateOrCreate(['plan' => $plan], $attributes);
        }

        $this->command?->info(sprintf('Plans seeded: %d.', count($rows)));
    }
}
