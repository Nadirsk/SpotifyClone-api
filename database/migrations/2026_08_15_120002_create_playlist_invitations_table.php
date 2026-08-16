<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // One active invite link per playlist — regenerating replaces it
            // rather than accumulating rows, so the unique index is on the FK
            // alone, not a composite.
            $table->foreignUuid('playlist_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('invited_by')->constrained('users')->cascadeOnDelete();

            // Stored plainly, not hashed: unlike an API key or password, this
            // token only grants collaboration on one playlist, and the owner
            // needs to re-display the same link every time they reopen
            // "Invite collaborators" — same trade-off a Google Docs/Notion/
            // Figma share link makes.
            $table->string('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_invitations');
    }
};
