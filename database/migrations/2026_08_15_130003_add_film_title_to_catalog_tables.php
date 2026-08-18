<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soundtrack provenance: which film or show a recording belongs to.
 *
 * The catalog provider has no soundtrack flag of its own, but it does encode
 * one in the title — JioSaavn names film recordings `Song (From "Film")`, which
 * `SoundtrackParser` extracts. Parsing it once into an indexed column, rather
 * than running a `LIKE '%(From %'` at query time, is what makes the browse hub
 * groupable and cheap; the raw title is left untouched so nothing downstream
 * that displays it has to change.
 *
 * Nullable everywhere: the overwhelming majority of the catalog is not from a
 * film, and null is the honest representation of "not a soundtrack" — a boolean
 * `is_soundtrack` alongside would be a second source of truth that could
 * disagree with this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('film_title')->nullable()->after('title');
            $table->index('film_title');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->string('film_title')->nullable()->after('title');
            $table->index('film_title');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex(['film_title']);
            $table->dropColumn('film_title');
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex(['film_title']);
            $table->dropColumn('film_title');
        });
    }
};
