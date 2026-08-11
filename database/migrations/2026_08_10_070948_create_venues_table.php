<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live events (`12_SCOPE_OF_WORK.md` has no source for this at all — built on
 * explicit request). First-party, seeded data: no provider gives this app a
 * concerts feed the way JioSaavn gives it a song catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('city');
            $table->string('address')->nullable();
            $table->timestamps();

            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
