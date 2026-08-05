<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->index();
            $table->text('cover_image')->nullable();
            $table->date('release_date')->nullable();
            $table->unsignedSmallInteger('total_tracks')->default(0);
            $table->unsignedTinyInteger('popularity')->default(0);
            $table->unsignedInteger('trending_score')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('release_date');
            $table->index('popularity');
            $table->index('trending_score');
            $table->index('last_synced_at');
            $table->fullText('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('albums');
    }
};
