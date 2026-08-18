<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the parts of a provider record the catalog had nowhere to put.
 *
 * The schema was designed around the fields every provider has in common
 * (11_PROVIDER_INTEGRATION §5), which is the right shape for a portable
 * catalog and the wrong shape for the requirement that everything a provider
 * offers ends up stored. JioSaavn hands back a label, a copyright line, an
 * explicit flag and a lyrics flag on every song, and a verification flag,
 * follower count, dominant type/language, date of birth and three social links
 * on every artist — all of which were being parsed, normalized and then
 * dropped on the floor for want of a column.
 *
 * Everything here is nullable or defaulted, so the columns cost nothing for
 * providers that do not supply them. SyncService::writeEntity() drops null
 * attributes before writing, which means a provider that knows the label and
 * one that does not can enrich the same row without the second blanking the
 * first.
 *
 * Deliberately NOT added:
 *
 * - **Lyrics text.** `has_lyrics` is a flag JioSaavn genuinely publishes;
 *   the lyrics themselves come from an endpoint this wrapper build does not
 *   expose (`lyrics.getLyrics` is in its endpoint table but has no route). A
 *   column that can only ever be null is worse than no column, so the flag
 *   goes in and the text waits for a wrapper that can fetch it.
 * - **A raw-payload JSON blob.** Tempting as a catch-all, but it would add
 *   several KB per row across a catalog measured in millions, and nothing can
 *   query it. Fields worth having are worth having as columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table): void {
            // The record label, as printed. Not normalized to its own table:
            // provider label strings are dirty enough ("Sony Music Entertainment
            // India Pvt. Ltd." vs "Sony Music India") that a lookup table would
            // fill with near-duplicates nobody could reconcile.
            $table->string('label')->nullable()->after('external_url');
            $table->text('copyright')->nullable()->after('label');
            $table->boolean('is_explicit')->default(false)->after('copyright');
            /*
             | Whether the provider has a lyrics document for this recording.
             | Not the lyrics — see the class docblock. Indexed because the
             | obvious use is a "songs with lyrics" filter, which without an
             | index is a full scan of the largest table in the schema.
             */
            $table->boolean('has_lyrics')->default(false)->after('is_explicit');

            $table->index('has_lyrics');
        });

        Schema::table('albums', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('title');
            $table->boolean('is_explicit')->default(false)->after('popularity');
        });

        Schema::table('artists', function (Blueprint $table): void {
            /*
             | The raw follower number, alongside the existing 0–100
             | `popularity` which is this rescaled. Both are wanted: popularity
             | is what sorts a listing cheaply, and the real number is what a
             | profile page shows and what makes two artists comparable at the
             | top of the scale, where the rescaled column saturates.
             */
            $table->unsignedBigInteger('follower_count')->default(0)->after('popularity');
            $table->boolean('is_verified')->default(false)->after('follower_count');
            // e.g. `singer`, `music director`, `actor` — how the provider files them.
            $table->string('dominant_type', 64)->nullable()->after('is_verified');
            $table->string('dominant_language', 64)->nullable()->after('dominant_type');
            /*
             | A string, not a date. Providers state this in whatever format
             | their CMS happened to store — a full date, a year alone, an empty
             | string — and a `date` column would reject most of them outright
             | rather than keep what was actually published.
             */
            $table->string('birth_date', 32)->nullable()->after('dominant_language');
            $table->text('facebook_url')->nullable()->after('birth_date');
            $table->text('twitter_url')->nullable()->after('facebook_url');
            $table->text('wiki_url')->nullable()->after('twitter_url');
            // Languages the artist has a catalog in, as the provider lists them.
            $table->json('available_languages')->nullable()->after('wiki_url');

            $table->index('follower_count');
            $table->index('is_verified');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table): void {
            $table->dropIndex(['has_lyrics']);
            $table->dropColumn(['label', 'copyright', 'is_explicit', 'has_lyrics']);
        });

        Schema::table('albums', function (Blueprint $table): void {
            $table->dropColumn(['description', 'is_explicit']);
        });

        Schema::table('artists', function (Blueprint $table): void {
            $table->dropIndex(['follower_count']);
            $table->dropIndex(['is_verified']);
            $table->dropColumn([
                'follower_count', 'is_verified', 'dominant_type', 'dominant_language',
                'birth_date', 'facebook_url', 'twitter_url', 'wiki_url', 'available_languages',
            ]);
        });
    }
};
