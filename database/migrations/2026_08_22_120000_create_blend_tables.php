<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blend — combines two or more users' music taste into one generated,
 * periodically-refreshed playlist. No source in 01-11; built on explicit
 * request, following the same private-by-default, invite-based shape as
 * Listen Together (`listening_rooms`) and playlist collaboration
 * (`playlist_collaborators`/`playlist_invitations`).
 *
 * Four tables rather than reusing playlists: a Blend is not a playlist a
 * member added songs to — it is a fully generated, disposable-and-regenerable
 * ranking (`blend_songs.score`/`reason`) with its own membership and its own
 * invitation shape (see `blend_invitations`' own doc for why that one differs
 * from `playlist_invitations`). "Save as Playlist" is the bridge to a real
 * playlist when a member wants to keep it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');

            /*
             | True until the creator explicitly renames it (BlendService::
             | rename() flips it off). While true, BlendInvitationService::
             | accept() is allowed to auto-rename the Blend to "A + B" the
             | moment the second member joins — the same "Aaibuzz + Vishal"
             | naming Spotify's own Blend uses — without clobbering a name a
             | human already chose.
             */
            $table->boolean('title_is_default')->default(true);

            // 0-100, null until the first generation has run. See
            // BlendGenerationService::matchScore() — a transparent overlap
            // score, deliberately not the same number as any blend_songs.score.
            $table->unsignedTinyInteger('match_score')->nullable();

            /*
             | Denormalized off blend_songs, same reasoning as
             | Playlist::tracks_count/total_duration: a Blend listing renders
             | "120 songs, about 3 hr 45 min" for every row on the page, and
             | doing that from a live join per row (or eager-loading the whole
             | tracklist per row) is the N+1 this column exists to avoid.
             | Written by EloquentBlendRepository::replaceSongs() in the same
             | transaction as the tracklist itself, so the two can never disagree.
             */
            $table->unsignedInteger('tracks_count')->default(0);
            $table->unsignedInteger('total_duration')->default(0);

            $table->timestamp('last_generated_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'created_at']);
        });

        Schema::create('blend_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blend_id')->constrained('blends')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // 'creator' | 'member' — see App\Enums\BlendMemberRole. The creator
            // is also named on the blend row (`created_by`), which is the copy
            // authorization reads; this column exists so a member list can be
            // rendered without a second lookup per row.
            $table->string('role', 16)->default('member');

            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            // One membership row per person per Blend.
            $table->unique(['blend_id', 'user_id']);
            // "Every Blend I'm in" — BlendRepository::paginateForUser().
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('blend_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blend_id')->constrained('blends')->cascadeOnDelete();
            $table->foreignUuid('invited_by')->constrained('users')->cascadeOnDelete();

            /*
             | The one deliberate difference from `playlist_invitations`: this
             | is addressed to a specific account, not a bare shareable link.
             | The product flow is "search a user, invite them, they get an
             | Accept/Decline notification" — a stranger who finds the link
             | must not be able to join somebody else's private Blend
             | (12_SCOPE_OF_WORK §23), so the token alone is not enough; the
             | signed-in caller has to also *be* this row's invited_user_id.
             | See BlendInvitationService::accept().
             */
            $table->foreignUuid('invited_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('token', 64)->unique();

            // pending | accepted | declined | revoked — App\Enums\BlendInvitationStatus.
            // Unlike a playlist's reusable link, this one is consumed by a
            // response: accepting or declining is a one-time event with a
            // status the sender can see, not a link that quietly keeps working.
            $table->string('status', 16)->default('pending');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            // Re-inviting the same person replaces their one active invitation
            // (BlendRepository::putInvitation() is an updateOrCreate on this
            // pair) rather than piling up duplicate rows.
            $table->unique(['blend_id', 'invited_user_id']);
            // "My pending Blend invitations" — the notification's own href
            // carries the token, but this is what a future "Invitations" list
            // or an admin/support lookup would query.
            $table->index(['invited_user_id', 'status']);
        });

        Schema::create('blend_songs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blend_id')->constrained('blends')->cascadeOnDelete();
            $table->foreignUuid('song_id')->constrained('songs')->cascadeOnDelete();

            /*
             | The ranking score BlendGenerationService computed — internal
             | only (12_SCOPE_OF_WORK §9: "do not expose unnecessary internal
             | scoring data to the frontend"). `float` rather than an integer
             | fixed-point column like `trending_score`: nothing here is
             | aggregated in SQL the way trending's decay sum is, so there is
             | no packet-size reason to fix the point, and the raw score is
             | never rendered — only its resulting `position` is.
             */
            $table->float('score')->default(0);

            // shared | taste | discover — App\Enums\BlendReason. Exposed on
            // BlendTrackResource for a client that wants to group or label the
            // list; the reference UI renders one flat list and ignores it.
            $table->string('reason', 16)->default('discover');

            $table->unsignedInteger('position');
            $table->timestamps();

            // A regeneration replaces every row for the Blend in one
            // transaction (BlendRepository::replaceSongs()), so this is
            // "was this song in the previous generation" more than a write
            // constraint — but it also stops one generation pass from
            // accidentally scoring the same song twice into two positions.
            $table->unique(['blend_id', 'song_id']);
            // The tracklist read — every request for a Blend's songs.
            $table->index(['blend_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blend_songs');
        Schema::dropIfExists('blend_invitations');
        Schema::dropIfExists('blend_members');
        Schema::dropIfExists('blends');
    }
};
