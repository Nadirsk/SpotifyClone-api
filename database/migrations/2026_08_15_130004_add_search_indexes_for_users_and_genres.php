<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FULLTEXT indexes for the two search types added alongside the Profiles and
 * Genres tabs.
 *
 * `DatabaseSearchEngine::matchText()` issues `MATCH … AGAINST` against the
 * type's own column, which errors outright without an index rather than merely
 * running slowly — so this migration is a hard prerequisite for those types
 * being registered in `config/search.php`, not an optimisation.
 *
 * The songs/artists/albums tables already carry theirs from their create
 * migrations; these two were never searchable before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->fullText('name');
        });

        Schema::table('genres', function (Blueprint $table) {
            $table->fullText('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropFullText(['name']);
        });

        Schema::table('genres', function (Blueprint $table) {
            $table->dropFullText(['name']);
        });
    }
};
