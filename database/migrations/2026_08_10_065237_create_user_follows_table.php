<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-to-user following — a public social profile (12_SCOPE_OF_WORK.md has
 * no source for this either; built on the user's explicit request, same as
 * `artist_follows`). Deliberately a separate table: this is a person
 * following a person, not a person following an artist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('followed_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['follower_id', 'followed_id']);
            // Drives "X's followers" / "X's following", newest first.
            $table->index(['followed_id', 'created_at']);
            $table->index(['follower_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_follows');
    }
};
