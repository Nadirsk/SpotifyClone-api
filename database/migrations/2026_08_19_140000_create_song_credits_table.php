<?php

declare(strict_types=1);

use App\Enums\CreditRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who else is on the record.
 *
 * `songs.artist_id` is one column and there are usually four or five people
 * behind a track — a singer, a composer, a lyricist, a featured guest. The
 * consequence was not cosmetic: an artist's song list could only ever be the
 * rows where the sync adapter happened to elect them the display artist, so a
 * music director's page showed a handful of tracks and silently omitted most of
 * what they had written. This table is the missing half.
 *
 * ## `artist_id` stays on `songs`
 *
 * It is not made redundant by this and it is not being migrated away. It is the
 * *display* artist — the one name a row in a tracklist has room for, already
 * resolved through DeduplicationService and depended on by every listing query,
 * the FULLTEXT search index and `NOT NULL`. Credits are additive: they widen
 * what an artist is found under without changing what a song is labelled with.
 *
 * ## Why a role column rather than a boolean
 *
 * `is_featured` would answer one question and lose the rest. The provider
 * distinguishes six kinds of credit and they are not interchangeable — a
 * lyricist belongs in a credits block but not in "songs they perform", and a
 * film actor credited `starring` belongs in neither (see
 * {@see CreditRole::isMusicCredit()}). The role has to survive to the query for
 * those distinctions to be makeable at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('song_credits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('song_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('artist_id')->constrained()->cascadeOnDelete();
            /*
             | Normalized by CreditRole, never the provider's own token — a
             | client must not be able to tell JioSaavn's `music` from another
             | provider's `composer` (11_PROVIDER_INTEGRATION §5). Length is
             | generous against the enum's longest case so adding a role later
             | is not a column change.
             */
            $table->string('role', 32);
            /*
             | Where the provider listed this person within their role, 0-based.
             | Two singers on a duet are ordered, and the order is information —
             | it decides which name a truncated credit line shows.
             */
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            /*
             | One person, one role, one song. The same artist legitimately
             | appears twice with two different roles (Sachin-Jigar composing
             | and singing on their own track), so the role is part of identity
             | rather than something to collapse on.
             |
             | This is also what makes the backfill re-runnable: it upserts
             | against this key, so an interrupted pass can simply be run again.
             */
            $table->unique(['song_id', 'artist_id', 'role']);
            /*
             | The discography query: every song an artist is credited on, in
             | one of the music roles. Leads with artist_id and carries role
             | because the query always constrains both.
             */
            $table->index(['artist_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_credits');
    }
};
