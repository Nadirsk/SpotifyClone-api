<?php

declare(strict_types=1);

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid tiers.
 *
 * A history table, not a one-row-per-user state table: `user_id` is indexed but
 * deliberately *not* unique, so an upgrade, a lapse and a resubscribe each leave
 * their own row. `SubscriptionService::currentFor()` reads the newest one. The
 * alternative — mutating a single row — would make "has this account ever been
 * on Premium before?" unanswerable, and the two-months-introductory offer on the
 * plans page depends on exactly that question.
 *
 * Money is stored in minor units as an integer (`amount_minor`) with its
 * currency alongside. Never a float, and never a bare number without the
 * currency next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->enum('plan', SubscriptionPlan::values());
            $table->enum('status', SubscriptionStatus::values())
                ->default(SubscriptionStatus::Active->value);

            $table->string('currency', 3);
            $table->unsignedInteger('amount_minor');

            $table->timestamp('started_at');
            /*
             | What entitlement actually hangs off — a cancelled subscription
             | still grants Premium until this passes. Nullable for a plan with
             | no end date, which nothing sells today but the column should not
             | have to change if one appears.
             */
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            /*
             | The payment processor's own id. Nullable because the current
             | checkout is simulated (no gateway); when a real one is wired in
             | this is where its reference lands, and it is unique so a
             | duplicate webhook cannot create a second subscription.
             */
            $table->string('payment_reference')->nullable()->unique();

            $table->timestamps();

            // The hot read: "the newest row for this user".
            $table->index(['user_id', 'created_at']);
            // Sweeping expired subscriptions on a schedule.
            $table->index(['status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
