<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /plans.
 *
 * The assertions that matter here are about the *discount*: the commercial rule
 * is "20% below the reference price", and it is derived rather than stored
 * (`PlanCatalog::priceFor()`). A test that only checked the number was ₹111.20
 * would pass just as happily against a hardcoded constant, so these check the
 * arithmetic relationship instead.
 */
final class PlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_are_public(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.current_plan', null);
    }

    public function test_every_paid_plan_is_priced_exactly_the_discount_below_its_reference(): void
    {
        $response = $this->getJson('/api/v1/plans')->assertOk();

        $rate = (float) config('plans.discount_rate');
        $paid = array_values(array_filter(
            $response->json('data.plans'),
            static fn (array $plan): bool => $plan['price'] !== null,
        ));

        $this->assertCount(3, $paid, 'Standard, Platinum and Student should all be purchasable.');

        foreach ($paid as $plan) {
            $price = $plan['price'];

            $this->assertSame(
                (int) round($price['reference_amount_minor'] * (1 - $rate)),
                $price['amount_minor'],
                "The {$plan['name']} plan is not {$price['discount_percent']}% below its reference price.",
            );

            $this->assertSame(
                $price['reference_amount_minor'] - $price['amount_minor'],
                // `saving` is formatted, so compare the arithmetic via the
                // minor units and only assert the string is consistent with it.
                (int) round((float) preg_replace('/[^\d.]/', '', $price['saving']) * 100),
            );
        }
    }

    public function test_the_free_plan_is_listed_with_no_price(): void
    {
        $response = $this->getJson('/api/v1/plans')->assertOk();

        $free = collect($response->json('data.plans'))->firstWhere('plan', 'free');

        $this->assertNotNull($free, 'Free must appear so the comparison table has a baseline column.');
        $this->assertNull($free['price']);
        $this->assertFalse($free['entitlements']['on_demand_playback']);
        $this->assertFalse($free['entitlements']['download']);
    }

    public function test_currency_defaults_to_the_users_country(): void
    {
        Sanctum::actingAs(User::factory()->create(['country' => 'IN']));

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('data.currency', 'INR');
    }

    public function test_currency_falls_back_to_the_default_for_an_unmapped_country(): void
    {
        Sanctum::actingAs(User::factory()->create(['country' => 'FR']));

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('data.currency', config('plans.default_currency'));
    }

    public function test_currency_can_be_overridden_by_the_query(): void
    {
        $response = $this->getJson('/api/v1/plans?currency=USD')->assertOk();

        $this->assertSame('USD', $response->json('data.currency'));

        foreach ($response->json('data.plans') as $plan) {
            $this->assertSame('USD', $plan['currency']);

            if ($plan['price'] !== null) {
                $this->assertStringStartsWith('$', $plan['price']['amount']);
            }
        }
    }

    public function test_an_unsupported_currency_is_ignored_rather_than_rejected(): void
    {
        // A stale client sending a currency we no longer sell in should still
        // see a price list, not a 422 that leaves the pricing page blank.
        $this->getJson('/api/v1/plans?currency=XYZ')
            ->assertOk()
            ->assertJsonPath('data.currency', config('plans.default_currency'));
    }

    public function test_the_intro_offer_is_only_available_to_an_account_that_never_subscribed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('data.intro_offer_available', true);

        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('data.intro_offer_available', false);
    }

    public function test_the_current_plan_is_reported_for_a_subscriber(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/subscription', ['plan' => 'platinum'])->assertCreated();

        $this->getJson('/api/v1/plans')
            ->assertOk()
            ->assertJsonPath('data.current_plan', 'platinum');
    }
}
