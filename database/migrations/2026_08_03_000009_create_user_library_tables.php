<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('song_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'song_id']);
            // Drives "my favorites, newest first".
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('song_id')->constrained()->cascadeOnDelete();
            $table->timestamp('played_at')->useCurrent();
            // Milliseconds actually listened, when the client reports it.
            $table->unsignedInteger('ms_played')->nullable();

            $table->index(['user_id', 'played_at']);
            // Trending counts plays per song inside a time window.
            $table->index(['song_id', 'played_at']);
            // Supports the per-user dedupe window without a full scan.
            $table->index(['user_id', 'song_id', 'played_at']);
        });

        Schema::create('search_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: guests search too, and their queries still feed analytics.
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('searched_at')->useCurrent();

            $table->index(['user_id', 'searched_at']);
            // Powers "popular searches" and zero-result reporting.
            $table->index(['keyword', 'searched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_history');
        Schema::dropIfExists('history');
        Schema::dropIfExists('favorites');
    }
};
