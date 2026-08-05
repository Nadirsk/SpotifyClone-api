<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->index();
            $table->text('bio')->nullable();
            $table->text('image')->nullable();
            $table->char('country', 2)->nullable();
            $table->unsignedTinyInteger('popularity')->default(0);
            $table->unsignedInteger('trending_score')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('popularity');
            $table->index('trending_score');
            $table->index('last_synced_at');
            $table->fullText('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
