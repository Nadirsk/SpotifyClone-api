<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artist following (12_SCOPE_OF_WORK.md FR.M — priced as an add-on, not in
 * `01`–`11`'s core scope, but built here on the user's explicit request).
 * Same shape as `favorites`: a plain per-user join row, not user-to-user
 * following, which is a separate, unrelated concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_follows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('artist_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'artist_id']);
            // Drives "my followed artists, newest first".
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_follows');
    }
};
