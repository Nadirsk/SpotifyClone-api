<?php

declare(strict_types=1);

use App\Enums\CreditRole;
use App\Models\Song;
use App\Observers\SongObserver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives every existing song a `primary` credit for its display artist.
 *
 * `song_credits` is relied upon as a complete superset of `songs.artist_id`:
 * {@see Song::scopeCreditedTo()} is a single indexed lookup rather
 * than an `OR` across two access paths, and that is only correct if an artist's
 * own labelled songs are in the credits table too.
 *
 * {@see SongObserver} keeps that true for every song written from
 * now on. This closes the gap behind it — the songs already in the table when
 * the invariant was introduced, which no future write will necessarily touch.
 *
 * A data migration rather than a console command because it is not optional.
 * A command can be forgotten, and a forgotten one here does not fail loudly: it
 * leaves artist pages quietly returning fewer songs than they should, or none.
 * Anywhere the schema is current, the invariant is current.
 *
 * Deliberately independent of `catalog:backfill-credits`. That command needs the
 * provider, takes minutes and can only ever cover songs the provider still
 * recognises; this needs nothing but the rows already present and is what makes
 * the query correct on a database that has never talked to a provider at all —
 * including a freshly migrated test database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = CreditRole::Primary->value;
        $now = now();
        $inserted = 0;

        /*
         | Chunked, and driven by a keyset walk over `songs.id` rather than
         | OFFSET. The table is ~36,000 rows here and much larger elsewhere, and
         | a single INSERT ... SELECT across all of it is one statement holding
         | every generated UUID — the same `max_allowed_packet` wall the credits
         | backfill hit at 1.4 MB of SQL.
         |
         | UUIDs are generated in PHP, not by MySQL's UUID(), so these keys are
         | v7 like every other primary key in the schema (HasUuids::newUniqueId)
         | rather than a v1 outlier that sorts differently.
         */
        $after = '';

        while (true) {
            $songs = DB::table('songs')
                ->select('id', 'artist_id')
                ->whereNull('deleted_at')
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(1000)
                ->get();

            if ($songs->isEmpty()) {
                break;
            }

            $after = (string) $songs->last()->id;

            $rows = [];

            foreach ($songs as $song) {
                if (! is_string($song->artist_id) || $song->artist_id === '') {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'song_id' => $song->id,
                    'artist_id' => $song->artist_id,
                    'role' => $role,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows === []) {
                continue;
            }

            /*
             | insertOrIgnore, because a provider backfill may already have
             | written this exact (song, artist, primary) row. Colliding on the
             | unique key means the fact is recorded, which is the goal — not an
             | error.
             */
            $inserted += DB::table('song_credits')->insertOrIgnore($rows);
        }

        if ($inserted > 0) {
            info("seed_display_artist_credits: inserted {$inserted} display-artist credit row(s).");
        }
    }

    /**
     * Deliberately not reversible.
     *
     * Rolling back would have to delete `primary` credit rows, and there is no
     * way to tell the ones this migration created from the ones a provider
     * genuinely published — they are the same row, written for the same reason.
     * Dropping all of them would destroy real credit data; dropping none is
     * harmless, because the table is created and dropped by the migration before
     * this one.
     */
    public function down(): void
    {
        //
    }
};
