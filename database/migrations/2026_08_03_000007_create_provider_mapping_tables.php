<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The three mapping tables are identical in shape, so they live in one
| migration rather than three near-duplicate files. They translate between a
| local entity and a provider's own ID, which is never exposed to clients
| (03_DATABASE_DESIGN §6).
*/
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'provider_song_mappings' => ['song_id', 'provider_song_id', 'songs'],
            'provider_artist_mappings' => ['artist_id', 'provider_artist_id', 'artists'],
            'provider_album_mappings' => ['album_id', 'provider_album_id', 'albums'],
        ] as $table => [$localColumn, $externalColumn, $localTable]) {
            Schema::create($table, function (Blueprint $blueprint) use ($localColumn, $externalColumn, $localTable) {
                $blueprint->uuid('id')->primary();
                $blueprint->foreignUuid($localColumn)->constrained($localTable)->cascadeOnDelete();
                $blueprint->foreignUuid('provider_id')->constrained()->cascadeOnDelete();
                $blueprint->string($externalColumn);
                // Hash of the normalized payload; lets sync skip unchanged records.
                $blueprint->string('checksum', 64)->nullable();
                $blueprint->timestamp('last_synced_at')->nullable();
                $blueprint->timestamps();

                // One provider cannot map the same external ID twice.
                $blueprint->unique(['provider_id', $externalColumn], "{$localTable}_provider_external_unique");
                // One provider maps to a given local entity at most once.
                $blueprint->unique(['provider_id', $localColumn], "{$localTable}_provider_local_unique");
                $blueprint->index('last_synced_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_album_mappings');
        Schema::dropIfExists('provider_artist_mappings');
        Schema::dropIfExists('provider_song_mappings');
    }
};
