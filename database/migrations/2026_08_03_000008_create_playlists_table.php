<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->text('cover_image')->nullable();
            $table->enum('visibility', ['public', 'private', 'unlisted'])->default('private');
            // Denormalized so listings do not need a COUNT per row.
            $table->unsignedSmallInteger('tracks_count')->default(0);
            $table->unsignedInteger('total_duration')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index('visibility');
            $table->fullText('title');
        });

        Schema::create('playlist_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('song_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamp('added_at')->useCurrent();

            // A song appears at most once in a playlist.
            $table->unique(['playlist_id', 'song_id']);
            $table->index(['playlist_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_tracks');
        Schema::dropIfExists('playlists');
    }
};
