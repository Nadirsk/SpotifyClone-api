<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the catalog hold JioSaavn's editorial playlists alongside users' own.
 *
 * `playlists` was built for user-owned lists only: `user_id` is NOT NULL and
 * foreign-keyed to `users`, so there was nowhere to put a provider playlist
 * that belongs to nobody. Rather than invent a synthetic "JioSaavn" user —
 * which would leak into every "playlists by user" listing, follower count and
 * collaboration check in the app — ownership is made optional and a `source`
 * column records which kind of row this is.
 *
 * `tracks_count` is widened at the same time. It was an unsignedSmallInteger,
 * and while no *user* builds a 65,535-track playlist, an editorial one is not
 * bounded by anyone's patience and the column would silently wrap.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | MySQL will not alter a column an active foreign key sits on, so the
         | constraint comes off, the column changes, and it goes back on. It is
         | re-added as cascadeOnDelete exactly as before — deleting a user must
         | still take their own playlists with them. Provider rows are
         | unaffected either way because their user_id is null.
         */
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->change();

            $table->string('source', 32)
                ->default('user')
                ->after('user_id')
                ->comment('user | jiosaavn — which system owns this row');
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            /*
             | Provider playlists are rebuilt wholesale on every refresh, so the
             | crawler needs to tell "this track is gone from the playlist" from
             | "this track was added by a collaborator". Only rows it owns are
             | ever truncated, and `source` is what scopes that.
             */
            $table->index(['source', 'visibility']);
        });

        /*
         | Both widened together. No *user* builds a 65,535-track playlist, but
         | an editorial one is not bounded by anyone's patience, and leaving
         | `position` narrow while `tracks_count` is wide would just move the
         | overflow one column across.
         */
        Schema::table('playlists', function (Blueprint $table): void {
            $table->unsignedInteger('tracks_count')->default(0)->change();
        });

        Schema::table('playlist_tracks', function (Blueprint $table): void {
            $table->unsignedInteger('position')->change();
        });

        /*
         | Matches the three existing provider_*_mappings tables exactly
         | (07_SYNC_ENGINE §7) — same columns, same two unique keys, same
         | checksum short-circuit. SyncService::writeMapping() is generic over
         | the model, so nothing there needs to change to drive this one.
         */
        Schema::create('provider_playlist_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('playlist_id')->constrained()->cascadeOnDelete();
            $table->string('provider_playlist_id');
            $table->string('checksum', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            /*
             | Named explicitly, and short: the name Laravel would generate for
             | the first key is 66 characters, over MySQL's 64-character
             | identifier limit, and the migration dies on it. The
             | `<entity>_provider_<side>_unique` shape is what the other three
             | provider_*_mappings tables already use.
             */
            $table->unique(['provider_id', 'provider_playlist_id'], 'playlists_provider_external_unique');
            $table->unique(['provider_id', 'playlist_id'], 'playlists_provider_local_unique');
            // Drives the incremental refresh's "oldest first" scan.
            $table->index('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_playlist_mappings');

        // Provider-owned rows have no owner to restore, so they cannot survive
        // user_id going back to NOT NULL.
        DB::table('playlists')->where('source', '!=', 'user')->delete();

        Schema::table('playlist_tracks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position')->change();
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropIndex(['source', 'visibility']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('source');
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->unsignedSmallInteger('tracks_count')->default(0)->change();
            $table->uuid('user_id')->nullable(false)->change();
        });

        Schema::table('playlists', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
