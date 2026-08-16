<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Models\Playlist;
use App\Models\PlaylistInvitation;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Spotify-style "Invite collaborators": one shareable, revocable link per
 * playlist. Covers the whole join flow plus how it interacts with a private
 * playlist's normal owner-only access.
 */
final class PlaylistCollaborationTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Invite link: create / regenerate / revoke
    // ---------------------------------------------------------------------

    public function test_owner_can_create_an_invite_link(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->private()->create();

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations");

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $this->assertIsString($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotNull($response->json('data.expires_at'));

        $this->assertDatabaseHas('playlist_invitations', [
            'playlist_id' => $playlist->id,
            'invited_by' => $owner->id,
        ]);
    }

    public function test_creating_an_invite_link_requires_authentication(): void
    {
        $playlist = Playlist::factory()->create();

        $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->assertStatus(401);
    }

    public function test_a_non_owner_cannot_create_an_invite_link(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->assertStatus(403);
    }

    public function test_regenerating_the_invite_link_invalidates_the_previous_token(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($owner);

        $first = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations");
        $firstToken = $first->json('data.token');

        $second = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations");
        $secondToken = $second->json('data.token');

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertSame(1, PlaylistInvitation::query()->where('playlist_id', $playlist->id)->count());

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/playlists/invitations/{$firstToken}/accept")->assertStatus(404);
        $this->postJson("/api/v1/playlists/invitations/{$secondToken}/accept")->assertOk();
    }

    public function test_owner_can_revoke_the_invite_link(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');

        $this->deleteJson("/api/v1/playlists/{$playlist->id}/invitations")->assertNoContent();

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertStatus(404);
    }

    // ---------------------------------------------------------------------
    // Invite preview (pre-login) and accept
    // ---------------------------------------------------------------------

    public function test_anyone_can_preview_a_valid_invite_link_without_signing_in(): void
    {
        $owner = User::factory()->create(['name' => 'Playlist Owner']);
        $playlist = Playlist::factory()->ownedBy($owner)->private()->create(['title' => 'Road Trip']);

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');

        $response = $this->getJson("/api/v1/playlists/invitations/{$token}");

        $response->assertOk();
        $response->assertJsonPath('data.playlist.id', $playlist->id);
        $response->assertJsonPath('data.playlist.title', 'Road Trip');
        $response->assertJsonPath('data.invited_by.name', 'Playlist Owner');
    }

    public function test_previewing_an_unknown_token_is_not_found(): void
    {
        $this->getJson('/api/v1/playlists/invitations/not-a-real-token')->assertStatus(404);
    }

    public function test_accepting_an_invite_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        // Built directly through the repository rather than an authenticated
        // request: `Sanctum::actingAs()` has no "log back out" counterpart
        // within a single test, so creating the link as the owner here would
        // leave every later request in this method authenticated as them too.
        $token = 'raw-test-token';
        app(\App\Contracts\Repositories\PlaylistRepository::class)
            ->putInvitation($playlist, $owner, $token, now()->addDays(30));

        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertStatus(401);
    }

    public function test_accepting_a_valid_invite_adds_the_caller_as_a_collaborator(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->private()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');

        Sanctum::actingAs($joiner);
        $response = $this->postJson("/api/v1/playlists/invitations/{$token}/accept");

        $response->assertOk();
        $response->assertJsonPath('data.id', $playlist->id);
        $this->assertDatabaseHas('playlist_collaborators', [
            'playlist_id' => $playlist->id,
            'user_id' => $joiner->id,
        ]);
    }

    public function test_accepting_the_same_invite_twice_is_idempotent_not_an_error(): void
    {
        $owner = User::factory()->create();
        $joiner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        $this->assertSame(
            1,
            \App\Models\PlaylistCollaborator::query()
                ->where('playlist_id', $playlist->id)
                ->where('user_id', $joiner->id)
                ->count(),
        );
    }

    public function test_the_owner_cannot_accept_their_own_invite_link(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');

        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // Private playlist visibility for a joined collaborator
    // ---------------------------------------------------------------------

    public function test_a_collaborator_can_view_a_private_playlist_a_stranger_cannot(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $stranger = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->private()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');
        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        $this->getJson("/api/v1/playlists/{$playlist->id}")->assertOk();

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/playlists/{$playlist->id}")->assertStatus(403);
    }

    public function test_a_joined_private_playlist_appears_in_the_collaborators_own_library_listing(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->private()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');
        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        $response = $this->getJson('/api/v1/playlists?limit=50');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($playlist->id, $ids);
    }

    // ---------------------------------------------------------------------
    // Edit rights: addSong / removeSong as a collaborator
    // ---------------------------------------------------------------------

    public function test_a_collaborator_can_add_and_remove_songs_when_the_playlist_is_collaborative(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->collaborative()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');
        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $song->id])->assertCreated();
        $this->deleteJson("/api/v1/playlists/{$playlist->id}/songs/{$song->id}")->assertNoContent();
    }

    public function test_a_collaborator_cannot_add_songs_when_the_playlist_is_not_collaborative(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        // Not ->collaborative(): joining is still possible via a standing
        // invite link, but is_collaborative gates edit rights independently.
        $playlist = Playlist::factory()->ownedBy($owner)->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');
        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $song->id])->assertStatus(403);
    }

    public function test_a_collaborator_cannot_rename_delete_or_invite_others(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->collaborative()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');
        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        $this->putJson("/api/v1/playlists/{$playlist->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/v1/playlists/{$playlist->id}")->assertStatus(403);
        $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->assertStatus(403);
    }

    // ---------------------------------------------------------------------
    // Collaborator management: list / remove / leave
    // ---------------------------------------------------------------------

    public function test_owner_can_list_and_remove_a_collaborator(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->collaborative()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');
        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        Sanctum::actingAs($owner);
        $list = $this->getJson("/api/v1/playlists/{$playlist->id}/collaborators");
        $list->assertOk();
        $this->assertContains($collaborator->id, collect($list->json('data'))->pluck('id')->all());

        $this->deleteJson("/api/v1/playlists/{$playlist->id}/collaborators/{$collaborator->id}")->assertNoContent();
        $this->assertDatabaseMissing('playlist_collaborators', [
            'playlist_id' => $playlist->id,
            'user_id' => $collaborator->id,
        ]);

        // Removed: no longer allowed to touch the (now-private-by-default) playlist.
        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => Song::factory()->create()->id])
            ->assertStatus(403);
    }

    public function test_removing_a_non_collaborator_is_not_found(): void
    {
        $owner = User::factory()->create();
        $notACollaborator = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/playlists/{$playlist->id}/collaborators/{$notACollaborator->id}")
            ->assertStatus(404);
    }

    public function test_a_collaborator_can_leave_but_the_owner_cannot(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->collaborative()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');

        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();
        $this->postJson("/api/v1/playlists/{$playlist->id}/leave")->assertNoContent();

        $this->assertDatabaseMissing('playlist_collaborators', [
            'playlist_id' => $playlist->id,
            'user_id' => $collaborator->id,
        ]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/playlists/{$playlist->id}/leave")->assertStatus(403);
    }

    // ---------------------------------------------------------------------
    // PlaylistResource fields
    // ---------------------------------------------------------------------

    public function test_playlist_resource_reports_is_collaborative_and_is_collaborator_correctly(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $stranger = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->public()->collaborative()->create();

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")->json('data.token');
        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        Sanctum::actingAs($owner);
        $ownerView = $this->getJson("/api/v1/playlists/{$playlist->id}");
        $ownerView->assertJsonPath('data.is_collaborative', true);
        $ownerView->assertJsonPath('data.is_collaborator', false);

        Sanctum::actingAs($collaborator);
        $collaboratorView = $this->getJson("/api/v1/playlists/{$playlist->id}");
        $collaboratorView->assertJsonPath('data.is_collaborator', true);

        Sanctum::actingAs($stranger);
        $strangerView = $this->getJson("/api/v1/playlists/{$playlist->id}");
        $strangerView->assertJsonPath('data.is_collaborator', false);
    }
}
