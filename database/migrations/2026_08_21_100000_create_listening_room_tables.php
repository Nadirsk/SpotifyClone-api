<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listen Together — the three tables behind a shared listening session.
 *
 * The room row is the authoritative playback state. Clients are the ones that
 * actually make sound, but they disagree: their clocks differ, their networks
 * lag, and a listener who reloads knows nothing at all. So the answer to "what
 * is this room playing, and where" has to live somewhere no single client owns,
 * and this is that place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listening_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             | The shareable code, and the room's public identity: it is what
             | /listen/{code} carries and what a listener reads out loud. Unique
             | rather than merely indexed, because two live rooms answering to
             | one code would split an invited group across both.
             */
            $table->string('room_code', 12)->unique();

            $table->foreignUuid('host_user_id')->constrained('users')->cascadeOnDelete();

            /*
             | Null before the host has pressed anything — a room can be created
             | and shared while its creator is still deciding what to play. Songs
             | are soft-deleted (see the Song model), so nullOnDelete is reachable
             | only by a hard delete; the room then survives it rather than
             | disappearing along with the track.
             */
            $table->foreignUuid('current_song_id')->nullable()->constrained('songs')->nullOnDelete();

            /*
             | Milliseconds, integer — deliberately not the seconds-as-float that
             | the audio element reports.
             |
             | This whole feature is an argument about sub-second accuracy, and a
             | float that round-trips through JSON, MySQL and back loses exactly
             | the precision the argument is about. Integer milliseconds are exact
             | at both ends, so the one lossy conversion happens in the client,
             | next to the element that produced the number.
             */
            $table->unsignedInteger('position_ms')->default(0);

            $table->boolean('is_playing')->default(false);

            /*
             | The instant `position_ms` was true, to the millisecond.
             |
             | Named for what it means rather than "last_playback_at": the pair
             | (position_ms, position_at) is a *measurement*, and a playing room's
             | real position is position_ms + (now - position_at). Without the
             | second half of the pair, a listener joining forty seconds after the
             | host pressed play would start forty seconds behind them.
             |
             | Fractional precision is the point. A whole-second column quantises
             | every join and every resync to a second of error, which is four
             | times the drift the clients bother correcting.
             */
            $table->timestamp('position_at', 3)->nullable();

            /*
             | A monotonic counter, bumped on every accepted playback write.
             |
             | Delivery is ordered per connection, but a client that reconnects
             | can receive a fresh snapshot and a replayed event in either order,
             | and applying the stale one would rewind the room. Comparing
             | versions is what makes an out-of-order arrival a no-op instead of a
             | jump backwards.
             */
            $table->unsignedBigInteger('playback_version')->default(0);

            /*
             | Rooms are closed, not deleted: the last member out stamps this (see
             | ListeningRoomService::leave), and a room whose members all vanished
             | without saying so is swept by `listening-rooms:prune`. Keeping the
             | row means a late-clicked invite can say "this room has ended"
             | instead of 404-ing as though the code had been mistyped.
             */
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            // "Is this room still live, and how long has it been quiet?" — the
            // prune command's only query.
            $table->index(['ended_at', 'updated_at']);
        });

        Schema::create('listening_room_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('listening_rooms')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            /*
             | 'host' | 'participant' — see App\Enums\ListeningRole. The host is
             | also named on the room row, which is the copy authorization reads;
             | this column is what will let a later release hand control to a
             | second member without that flag having to mean two things.
             */
            $table->string('role', 16)->default('participant');

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            /*
             | Stamped on leave rather than deleting the row, so re-joining a room
             | updates one row instead of churning insert/delete pairs, and so
             | "who was in this room" survives someone stepping out of it.
             */
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            // One membership row per person per room; re-joining reuses it.
            $table->unique(['room_id', 'user_id']);
            // The members list, and the "is anyone still here" check on leave.
            $table->index(['room_id', 'left_at']);
        });

        Schema::create('listening_room_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('listening_rooms')->cascadeOnDelete();
            $table->foreignUuid('song_id')->constrained('songs')->cascadeOnDelete();

            /*
             | Who put it there. Nullable and nullOnDelete: a queue outlives the
             | account of someone who added a track and later deleted their
             | profile, and losing the attribution beats deleting a row out from
             | under everyone still listening to it.
             */
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             | Play order, 0-based. Called `queue_position` rather than `position`
             | because this table sits next to the room's `position_ms` and the two
             | mean completely different things — one is an index into a list, the
             | other is a place inside a song.
             |
             | Deliberately not unique per room: a reorder rewrites the whole block
             | in one transaction, and a unique index would reject the intermediate
             | states of any rewrite that is not a pure rotation.
             */
            $table->unsignedInteger('queue_position');

            $table->timestamps();

            // Reading a room's queue in play order — the only query this table has.
            $table->index(['room_id', 'queue_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listening_room_queue');
        Schema::dropIfExists('listening_room_members');
        Schema::dropIfExists('listening_rooms');
    }
};
