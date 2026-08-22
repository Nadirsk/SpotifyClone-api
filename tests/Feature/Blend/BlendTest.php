<?php

declare(strict_types=1);

namespace Tests\Feature\Blend;

use App\Models\Blend;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Blend — create, invite a specific person, accept/decline, generate, refresh,
 * save as playlist, leave/remove. No source in 01-11; built on explicit
 * request (12_SCOPE_OF_WORK).
 */
final class BlendTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------

    public function test_creating_a_blend_requires_authentication(): void
    {
        $this->postJson('/api/v1/blends')->assertStatus(401);
    }

    public function test_creator_can_create_a_blend_with_a_default_title(): void
    {
        $creator = User::factory()->create(['name' => 'Aaibuzz']);
        Sanctum::actingAs($creator);

        $response = $this->postJson('/api/v1/blends');

        $response->assertCreated();
        $response->assertJsonPath('data.title', "Aaibuzz's Blend");
        $response->assertJsonPath('data.title_is_default', true);
        $response->assertJsonPath('data.is_creator', true);
        $response->assertJsonPath('data.is_member', true);
        $response->assertJsonPath('data.tracks_count', 0);

        $this->assertDatabaseHas('blend_members', [
            'blend_id' => $response->json('data.id'),
            'user_id' => $creator->id,
            'role' => 'creator',
        ]);
    }

    public function test_creator_can_create_a_blend_with_a_custom_title(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/blends', ['title' => 'Road Trip Mix']);

        $response->assertJsonPath('data.title', 'Road Trip Mix');
        $response->assertJsonPath('data.title_is_default', false);
    }

    // ---------------------------------------------------------------------
    // Invitations
    // ---------------------------------------------------------------------

    public function test_creator_can_invite_a_specific_user(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');

        $response = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $invitee->id]);

        $response->assertCreated();
        $this->assertIsString($response->json('data.token'));
        $this->assertDatabaseHas('blend_invitations', [
            'blend_id' => $blendId,
            'invited_user_id' => $invitee->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_non_creator_cannot_invite(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $member->id])
            ->json('data.token');

        Sanctum::actingAs($member);
        $this->postJson("/api/v1/blends/invitations/{$token}/accept")->assertOk();
        $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $stranger->id])
            ->assertStatus(403);
    }

    public function test_cannot_invite_self(): void
    {
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');

        $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $creator->id])
            ->assertStatus(422);
    }

    public function test_cannot_invite_an_existing_member_again(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $member->id])
            ->json('data.token');

        Sanctum::actingAs($member);
        $this->postJson("/api/v1/blends/invitations/{$token}/accept")->assertOk();

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $member->id])
            ->assertStatus(409);
    }

    public function test_anyone_can_preview_a_valid_invitation_without_signing_in(): void
    {
        $creator = User::factory()->create(['name' => 'Karan']);
        $invitee = User::factory()->create(['name' => 'Riya']);
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $invitee->id])
            ->json('data.token');

        $response = $this->getJson("/api/v1/blends/invitations/{$token}");

        $response->assertOk();
        $response->assertJsonPath('data.invited_by.name', 'Karan');
        $response->assertJsonPath('data.invited_user.name', 'Riya');
        $response->assertJsonPath('data.status', 'pending');
    }

    public function test_previewing_an_unknown_token_is_not_found(): void
    {
        $this->getJson('/api/v1/blends/invitations/not-a-real-token')->assertStatus(404);
    }

    public function test_a_wrong_account_cannot_accept_someone_elses_invitation(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $invitee->id])
            ->json('data.token');

        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/blends/invitations/{$token}/accept")->assertStatus(403);
    }

    public function test_declining_an_invitation_does_not_add_a_member(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $invitee->id])
            ->json('data.token');

        Sanctum::actingAs($invitee);
        $this->postJson("/api/v1/blends/invitations/{$token}/decline")->assertNoContent();

        $this->assertDatabaseMissing('blend_members', ['blend_id' => $blendId, 'user_id' => $invitee->id]);
        $this->assertDatabaseHas('blend_invitations', ['blend_id' => $blendId, 'status' => 'declined']);
    }

    // ---------------------------------------------------------------------
    // Accept -> generation
    // ---------------------------------------------------------------------

    public function test_accepting_activates_the_blend_renames_it_and_ranks_a_shared_favorite_first(): void
    {
        $creator = User::factory()->create(['name' => 'Aaibuzz']);
        $invitee = User::factory()->create(['name' => 'Vishal']);

        $shared = Song::factory()->create(['popularity' => 10]);
        $creatorOnly = Song::factory()->create(['popularity' => 10]);
        $inviteeOnly = Song::factory()->create(['popularity' => 10]);

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/favorites/{$shared->id}")->assertCreated();
        $this->postJson("/api/v1/favorites/{$creatorOnly->id}")->assertCreated();
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $invitee->id])
            ->json('data.token');

        Sanctum::actingAs($invitee);
        $this->postJson("/api/v1/favorites/{$shared->id}")->assertCreated();
        $this->postJson("/api/v1/favorites/{$inviteeOnly->id}")->assertCreated();

        $response = $this->postJson("/api/v1/blends/invitations/{$token}/accept");

        $response->assertOk();
        $response->assertJsonPath('data.title', 'Aaibuzz + Vishal');
        $response->assertJsonCount(2, 'data.members');
        $this->assertNotNull($response->json('data.match_score'));
        $this->assertGreaterThan(0, $response->json('data.match_score'));

        $tracks = collect($response->json('data.tracks'));
        $this->assertSame(3, $tracks->count());
        $this->assertSame($shared->id, $tracks->first()['id']);
        $this->assertSame('shared', $tracks->first()['blend_reason']);

        $this->assertDatabaseHas('blend_members', ['blend_id' => $blendId, 'user_id' => $invitee->id, 'role' => 'member']);
        $this->assertNotNull(Blend::query()->find($blendId)->last_generated_at);
    }

    public function test_generation_with_no_activity_at_all_succeeds_with_an_empty_tracklist(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();

        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $invitee->id])
            ->json('data.token');

        Sanctum::actingAs($invitee);
        $response = $this->postJson("/api/v1/blends/invitations/{$token}/accept");

        $response->assertOk();
        $response->assertJsonPath('data.tracks_count', 0);
        $response->assertJsonCount(0, 'data.tracks');
    }

    // ---------------------------------------------------------------------
    // Privacy
    // ---------------------------------------------------------------------

    public function test_a_stranger_cannot_view_a_private_blend(): void
    {
        $creator = User::factory()->create();
        $stranger = User::factory()->create();
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/blends/{$blendId}")->assertStatus(403);
    }

    // ---------------------------------------------------------------------
    // Refresh / save / leave / remove
    // ---------------------------------------------------------------------

    private function activeBlend(User $creator, User $invitee): string
    {
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $invitee->id])
            ->json('data.token');

        Sanctum::actingAs($invitee);
        $this->postJson("/api/v1/blends/invitations/{$token}/accept")->assertOk();

        return $blendId;
    }

    public function test_any_member_can_manually_refresh_the_blend(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $blendId = $this->activeBlend($creator, $invitee);

        // Already acting as $invitee from activeBlend().
        $this->postJson("/api/v1/blends/{$blendId}/refresh")->assertOk();
    }

    public function test_any_member_can_save_the_blend_as_a_playlist(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/favorites/{$song->id}")->assertCreated();
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');
        $token = $this->postJson("/api/v1/blends/{$blendId}/invitations", ['user_id' => $invitee->id])
            ->json('data.token');

        Sanctum::actingAs($invitee);
        $this->postJson("/api/v1/blends/invitations/{$token}/accept")->assertOk();

        $response = $this->postJson("/api/v1/blends/{$blendId}/save");

        $response->assertCreated();
        $this->assertDatabaseHas('playlists', [
            'id' => $response->json('data.id'),
            'user_id' => $invitee->id,
        ]);
        $this->assertDatabaseHas('playlist_tracks', [
            'playlist_id' => $response->json('data.id'),
            'song_id' => $song->id,
        ]);
    }

    public function test_a_member_can_leave_but_the_creator_cannot(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $blendId = $this->activeBlend($creator, $invitee);

        $this->postJson("/api/v1/blends/{$blendId}/leave")->assertNoContent();
        $this->assertDatabaseMissing('blend_members', ['blend_id' => $blendId, 'user_id' => $invitee->id]);

        Sanctum::actingAs($creator);
        $this->postJson("/api/v1/blends/{$blendId}/leave")->assertStatus(403);
    }

    public function test_creator_can_remove_a_member_but_not_themselves(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $blendId = $this->activeBlend($creator, $invitee);

        Sanctum::actingAs($creator);
        $this->deleteJson("/api/v1/blends/{$blendId}/members/{$invitee->id}")->assertNoContent();
        $this->assertDatabaseMissing('blend_members', ['blend_id' => $blendId, 'user_id' => $invitee->id]);

        $this->deleteJson("/api/v1/blends/{$blendId}/members/{$creator->id}")->assertStatus(422);
    }

    public function test_creator_can_delete_the_blend(): void
    {
        $creator = User::factory()->create();
        Sanctum::actingAs($creator);
        $blendId = $this->postJson('/api/v1/blends')->json('data.id');

        $this->deleteJson("/api/v1/blends/{$blendId}")->assertNoContent();
        $this->assertDatabaseMissing('blends', ['id' => $blendId]);
    }
}
