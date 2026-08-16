<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            // Independent of `visibility`: a private playlist can be
            // collaborative (invite-only), same as a public one.
            $table->boolean('is_collaborative')->default(false)->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('is_collaborative');
        });
    }
};
