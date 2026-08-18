<?php

declare(strict_types=1);

use App\Enums\AudioQuality;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The listener's *preferred* streaming and download quality.
 *
 * A preference, not an entitlement: it records what they asked for, and
 * `SubscriptionService` clamps it to what their plan allows at read time. Storing
 * the clamped value instead would silently downgrade a listener's setting the
 * moment their subscription lapsed, and they would have to set it again after
 * resubscribing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('audio_quality', AudioQuality::values())
                ->default(AudioQuality::Normal->value)
                ->after('language');

            // Whether they opted into keeping downloads for offline playback.
            $table->boolean('offline_enabled')->default(false)->after('audio_quality');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['audio_quality', 'offline_enabled']);
        });
    }
};
