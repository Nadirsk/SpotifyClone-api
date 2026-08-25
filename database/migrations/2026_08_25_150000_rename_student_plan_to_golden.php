<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renames the `student` tier to `golden` everywhere it is stored, following
 * `App\Enums\SubscriptionPlan` — see that enum for why this is a rename and
 * not a new tier.
 *
 * `subscriptions.plan` is a MySQL `ENUM` column (built from
 * `SubscriptionPlan::values()` when the table was created), so the column
 * definition itself has to widen before existing `student` rows can move to
 * `golden`, then narrow again once none are left. `plans.plan` is a plain
 * string primary key, so it only needs the data update.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE subscriptions MODIFY plan ENUM('free', 'standard', 'platinum', 'student', 'golden') NOT NULL");

        DB::table('subscriptions')->where('plan', 'student')->update(['plan' => 'golden']);
        DB::table('plans')->where('plan', 'student')->update([
            'plan' => 'golden',
            'name' => 'Golden',
            'tagline' => 'Standard, for Golden members.',
        ]);

        DB::statement("ALTER TABLE subscriptions MODIFY plan ENUM('free', 'standard', 'platinum', 'golden') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE subscriptions MODIFY plan ENUM('free', 'standard', 'platinum', 'student', 'golden') NOT NULL");

        DB::table('subscriptions')->where('plan', 'golden')->update(['plan' => 'student']);
        DB::table('plans')->where('plan', 'golden')->update([
            'plan' => 'student',
            'name' => 'Student',
            'tagline' => 'Standard, for verified students.',
        ]);

        DB::statement("ALTER TABLE subscriptions MODIFY plan ENUM('free', 'standard', 'platinum', 'student') NOT NULL");
    }
};
