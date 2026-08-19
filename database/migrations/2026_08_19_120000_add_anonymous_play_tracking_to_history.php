<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a play be recorded without an account, and makes "today's plays" a
 * cheap query.
 *
 * ## Why guests have to count
 *
 * `history.user_id` was NOT NULL, and `POST /history` sits behind
 * `auth:sanctum`. Signed-out listening was therefore invisible — which matters
 * because it is the same table every popularity figure in the product is
 * derived from (`RefreshTrendingJob`, `trending_score`, "Top songs today").
 * A chart built only from signed-in plays is not a chart of what is being
 * listened to; it is a chart of what subscribers listen to, presented as the
 * former.
 *
 * `session_id` is an opaque per-browser identifier the client generates and
 * keeps in `localStorage`. It exists for exactly one purpose — deduping repeat
 * plays of the same song by the same listener, the same window the signed-in
 * path already applies — and is deliberately not tied to an IP, a fingerprint
 * or anything that outlives the browser storage it lives in.
 *
 * ## The index
 *
 * The existing indexes all lead on `user_id` or `song_id`, so "every play since
 * midnight" had no index to use and scanned. `(played_at, song_id)` serves that
 * grouping directly, and leaves the older composite ones in place for the
 * per-user feed and the dedupe check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('history', function (Blueprint $table): void {
            /*
             | Dropping and re-adding the FK is unavoidable: MySQL will not
             | change a column's nullability while a foreign key references it.
             | The constraint comes back identically, so the only net change is
             | that NULL is now accepted.
             */
            $table->dropForeign(['user_id']);
        });

        Schema::table('history', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable()->change();
            $table->string('session_id', 64)->nullable()->after('user_id');
        });

        Schema::table('history', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            // The guest counterpart of the (user_id, song_id, played_at) dedupe index.
            $table->index(['session_id', 'song_id', 'played_at']);
            // "Top songs today": group every play since midnight by song.
            $table->index(['played_at', 'song_id']);
        });
    }

    public function down(): void
    {
        Schema::table('history', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['session_id', 'song_id', 'played_at']);
            $table->dropIndex(['played_at', 'song_id']);
            $table->dropColumn('session_id');
        });

        Schema::table('history', function (Blueprint $table): void {
            $table->uuid('user_id')->nullable(false)->change();
        });

        Schema::table('history', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
