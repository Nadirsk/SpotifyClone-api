<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's own database-notification table, hand-written rather than published
 * so the morph columns use `uuidMorphs` — every id in this schema is a UUID, and
 * the stock stub's `morphs()` would emit a `bigint` `notifiable_id` that no user
 * row could ever match.
 *
 * Using the framework's table (and `User`'s existing `Notifiable`) rather than a
 * bespoke one buys `markAsRead()`, `unreadNotifications`, and the whole
 * `Notification::send()` pipeline — including queueing — for free. The payload
 * shape inside `data` is this app's own contract and is documented on each
 * `App\Notifications\*` class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            /*
             | The inbox query is "this user's notifications, newest first", and
             | the bell badge is "…where read_at is null". `uuidMorphs` already
             | indexes (notifiable_type, notifiable_id); this adds the ordering
             | and the unread filter on top of it.
             */
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
