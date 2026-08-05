<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // Matches the adapter key registered in App\Services\Providers.
            $table->string('api_name', 50)->unique();
            $table->boolean('enabled')->default(false);
            // Lower runs first when merging metadata (11_PROVIDER_INTEGRATION §10).
            $table->unsignedTinyInteger('priority')->default(100);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
