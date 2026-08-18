<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('venue_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('date');
            /*
             | A run of shows (e.g. "Dec 8-9") displays a label and a count
             | instead of the single-day chip a one-night event gets. Both
             | nullable: most concerts are a single night and need neither.
             */
            $table->string('date_label')->nullable();
            $table->unsignedSmallInteger('event_count')->nullable();
            /*
             | This event taxonomy ("Pop", "Rock", "Bollywood"...) is a
             | separate, event-specific tag list — not the catalog's own
             | `genres` table, which describes songs. A JSON array is enough
             | for a set of tags nothing else joins against; a pivot table
             | would be real machinery for a filter chip list.
             */
            $table->json('genres')->nullable();
            /** @var list<array{name: string, price: string}>|null */
            $table->json('vendors')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concerts');
    }
};
