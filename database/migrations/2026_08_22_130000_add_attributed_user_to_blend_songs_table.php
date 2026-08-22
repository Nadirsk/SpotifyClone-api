<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Added by" — which member's taste this pick is credited to, the way real
 * Spotify's Blend tracklist shows one member's avatar per row. Nullable: a
 * `discover` pick that came from catalog fan-out with no clean single-member
 * match has nobody to credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blend_songs', function (Blueprint $table) {
            $table->foreignUuid('attributed_user_id')
                ->nullable()
                ->after('reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('blend_songs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attributed_user_id');
        });
    }
};
