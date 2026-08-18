<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_reports_the_free_tier_rather_than_404_for_a_new_account(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.is_premium', false)
            ->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.entitlements.download', false);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/subscription')->assertStatus(401);
    }

    public function test_subscribing_creates_an_entitling_row_and_unlocks_the_plans_capabilities(): void
    {
        $user = User::factory()->create(['country' => 'IN']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])
            ->assertCreated()
            ->assertJsonPath('data.plan', 'standard')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.entitled', true)
            ->assertJsonPath('data.currency', 'INR');

        $this->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.entitlements.download', true)
            ->assertJsonPath('data.entitlements.on_demand_playback', true)
            ->assertJsonPath('data.entitlements.max_audio_quality', 'very_high');
    }

    /**
     * `effective_audio_quality` is the listener's *preference* clamped to the
     * plan ceiling — not the ceiling itself. A Premium account that never
     * changed its setting still streams at Normal, which is correct and is the
     * distinction the settings screen renders.
     */
    public function test_effective_quality_is_the_preference_clamped_not_the_ceiling(): void
    {
        $user = User::factory()->create(['audio_quality' => 'very_high']);
        Sanctum::actingAs($user);

        // Free: the preference survives, the stream is clamped.
        $this->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.effective_audio_quality', 'normal');

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        // Premium: the same untouched preference is now honoured in full.
        $this->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.effective_audio_quality', 'very_high');

        $this->assertSame('very_high', $user->refresh()->audio_quality->value);
    }

    public function test_a_lapsed_plan_downgrades_the_stream_without_destroying_the_preference(): void
    {
        $user = User::factory()->create(['audio_quality' => 'very_high']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();
        $this->travel(2)->months();

        $this->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.effective_audio_quality', 'normal');

        // The setting itself is untouched, so resubscribing restores it.
        $this->assertSame('very_high', $user->refresh()->audio_quality->value);
    }

    public function test_the_amount_charged_matches_the_discounted_catalog_price(): void
    {
        $user = User::factory()->create(['country' => 'IN']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $reference = (int) config('plans.standard.reference_price.INR');
        $expected = (int) round($reference * (1 - (float) config('plans.discount_rate')));

        $this->assertSame($expected, $response->json('data.amount_minor'));
    }

    public function test_the_billing_currency_can_be_chosen_explicitly(): void
    {
        Sanctum::actingAs(User::factory()->create(['country' => 'IN']));

        $this->postJson('/api/v1/subscription', ['plan' => 'platinum', 'currency' => 'USD'])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_the_free_plan_cannot_be_purchased(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/subscription', ['plan' => 'free'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    public function test_resubscribing_to_the_same_active_plan_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertStatus(409);
    }

    public function test_upgrading_expires_the_previous_subscription_so_only_one_ever_entitles(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();
        $this->postJson('/api/v1/subscription', ['plan' => 'platinum'])->assertCreated();

        // History is kept — that is what `hasEverSubscribed()` reads — but only
        // the newest row may still be entitling.
        $this->assertSame(2, Subscription::query()->where('user_id', $user->id)->count());

        $entitling = Subscription::query()
            ->where('user_id', $user->id)
            ->get()
            ->filter(static fn (Subscription $row): bool => $row->isEntitled());

        $this->assertCount(1, $entitling);
        $this->assertSame(SubscriptionPlan::Platinum, $entitling->first()?->plan);
    }

    public function test_cancelling_keeps_entitlements_until_the_period_ends(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $this->deleteJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            // The whole point: cancelled but still entitled.
            ->assertJsonPath('data.entitled', true);

        $this->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.entitlements.download', true);
    }

    public function test_entitlements_lapse_once_the_period_end_passes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        /*
         | Travelling past the period end rather than editing the row: this
         | asserts that `isEntitled()` reads the clock, which is what stops a
         | subscription nothing has swept yet from granting Premium forever.
         */
        $this->travel(2)->months();

        $this->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.is_premium', false)
            ->assertJsonPath('data.entitlements.download', false);
    }

    public function test_cancelling_without_a_subscription_is_a_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/subscription')->assertStatus(404);
    }

    public function test_expire_lapsed_marks_finished_subscriptions_without_touching_live_ones(): void
    {
        $lapsed = Subscription::query()->create([
            'user_id' => User::factory()->create()->id,
            'plan' => SubscriptionPlan::Standard,
            'status' => SubscriptionStatus::Active,
            'currency' => 'INR',
            'amount_minor' => 11120,
            'started_at' => Carbon::now()->subMonths(2),
            'current_period_end' => Carbon::now()->subDay(),
        ]);

        $live = Subscription::query()->create([
            'user_id' => User::factory()->create()->id,
            'plan' => SubscriptionPlan::Platinum,
            'status' => SubscriptionStatus::Active,
            'currency' => 'INR',
            'amount_minor' => 23920,
            'started_at' => Carbon::now(),
            'current_period_end' => Carbon::now()->addMonth(),
        ]);

        $swept = app(\App\Services\Billing\SubscriptionService::class)->expireLapsed();

        $this->assertSame(1, $swept);
        $this->assertSame(SubscriptionStatus::Expired, $lapsed->refresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $live->refresh()->status);
    }

    public function test_a_subscription_notification_is_written_for_both_events(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();
        $this->deleteJson('/api/v1/subscription')->assertOk();

        $this->assertSame(2, $user->notifications()->count());
        $this->assertSame(2, $user->unreadNotifications()->count());
    }
}
