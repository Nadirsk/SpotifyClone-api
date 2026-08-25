<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the pricing/entitlement content out of config/plans.php and into the
 * database, so an admin can change a price or a feature flag without a code
 * deploy. `PlanCatalog` reads this table now instead of `config('plans.*')`.
 *
 * `plan` is the primary key — the same string `App\Enums\SubscriptionPlan`
 * uses everywhere else (`subscriptions.plan`, entitlement checks). There are
 * exactly four rows, seeded once, one per enum case; nothing creates or
 * deletes a row, because adding a genuinely new tier means adding an enum
 * case and touching every `match`/entitlement gate that switches on it —
 * this table cannot make that safe by itself.
 *
 * `discount_rate`, `currencies`, `default_currency` and `currency_by_country`
 * stay in config/plans.php: they are site-wide billing policy, not per-plan
 * content, and every plan currently shares one discount rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->string('plan', 32)->primary();
            $table->string('name');
            $table->string('tagline');
            // Seats — display copy only, not enforced. See the old config's
            // "accounts versus max_sessions" note, preserved on the Plan model.
            $table->unsignedTinyInteger('accounts')->default(1);
            // Concurrent logged-in devices. Null = uncapped (Free).
            $table->unsignedTinyInteger('max_sessions')->nullable();
            // Reference (Spotify list) prices in minor units. Null for Free —
            // see PlanCatalog::describe() for why that must stay null, not zero.
            $table->unsignedInteger('reference_price_inr')->nullable();
            $table->unsignedInteger('reference_price_usd')->nullable();
            /** @var array<string, mixed> */
            $table->json('entitlements');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
