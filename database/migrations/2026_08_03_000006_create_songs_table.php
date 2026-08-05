<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('songs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('artist_id')->constrained()->cascadeOnDelete();
            // Nullable: singles are not attached to an album.
            $table->foreignUuid('album_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('genre_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            /*
             | Position on the album. Nullable because singles have no album and
             | some providers omit it. Without this an album's running order
             | would fall back to insertion order, which is wrong for anything
             | synced out of sequence.
             */
            $table->unsignedSmallInteger('track_number')->nullable();
            // Seconds.
            $table->unsignedInteger('duration')->default(0);
            /*
             | International Standard Recording Code — the preferred dedupe key
             | (07_SYNC_ENGINE §6). Not unique: provider data is dirty enough
             | that enforcing it would fail syncs rather than surface conflicts.
             */
            $table->string('isrc', 15)->nullable()->index();
            $table->date('release_date')->nullable();
            $table->unsignedTinyInteger('popularity')->default(0);
            $table->unsignedInteger('trending_score')->default(0);
            $table->unsignedBigInteger('play_count')->default(0);
            $table->text('preview_url')->nullable();
            $table->text('external_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('popularity');
            $table->index('trending_score');
            $table->index('release_date');
            $table->index('duration');
            $table->index('last_synced_at');
            // Album track listing in running order.
            $table->index(['album_id', 'track_number']);
            // Covers the common "this genre, most popular first" listing.
            $table->index(['genre_id', 'popularity']);
            $table->index(['language_id', 'popularity']);
            $table->fullText('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
