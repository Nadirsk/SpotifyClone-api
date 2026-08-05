<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PlaylistTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // store
    // ---------------------------------------------------------------------

    public function test_store_creates_playlist_with_valid_visibility(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/playlists', [
            'title' => 'Late Night Drive',
            'description' => 'Chill songs for the road',
            'visibility' => 'public',
            'cover_image' => 'https://example.test/cover.jpg',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.title', 'Late Night Drive');
        $response->assertJsonPath('data.visibility', 'public');
        $response->assertJsonPath('data.tracks_count', 0);
        $response->assertJsonPath('data.total_duration', 0);
        $response->assertJsonPath('data.owner.id', $user->id);

        $this->assertDatabaseHas('playlists', [
            'title' => 'Late Night Drive',
            'user_id' => $user->id,
            'visibility' => 'public',
        ]);
    }

    public function test_store_rejects_an_invalid_visibility_value(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/playlists', [
            'title' => 'Bad Playlist',
            'visibility' => 'super-public',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonValidationErrors('visibility');
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/playlists', [
            'title' => 'Nope',
            'visibility' => 'public',
        ]);

        $response->assertStatus(401);
    }

    // ---------------------------------------------------------------------
    // index visibility rules
    // ---------------------------------------------------------------------

    public function test_index_shows_the_viewers_own_private_and_unlisted_playlists_alongside_others_public_ones(): void
    {
        $viewer = User::factory()->create();
        $stranger = User::factory()->create();

        $ownPrivate = Playlist::factory()->ownedBy($viewer)->private()->create();
        $ownUnlisted = Playlist::factory()->ownedBy($viewer)->unlisted()->create();
        $ownPublic = Playlist::factory()->ownedBy($viewer)->public()->create();

        $strangerPublic = Playlist::factory()->ownedBy($stranger)->public()->create();
        $strangerPrivate = Playlist::factory()->ownedBy($stranger)->private()->create();
        $strangerUnlisted = Playlist::factory()->ownedBy($stranger)->unlisted()->create();

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/playlists?limit=50');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownPrivate->id, $ids);
        $this->assertContains($ownUnlisted->id, $ids);
        $this->assertContains($ownPublic->id, $ids);
        $this->assertContains($strangerPublic->id, $ids);
        $this->assertNotContains($strangerPrivate->id, $ids);
        $this->assertNotContains($strangerUnlisted->id, $ids);
    }

    public function test_index_shows_only_public_playlists_to_an_unauthenticated_guest(): void
    {
        $owner = User::factory()->create();

        $public = Playlist::factory()->ownedBy($owner)->public()->create();
        $private = Playlist::factory()->ownedBy($owner)->private()->create();
        $unlisted = Playlist::factory()->ownedBy($owner)->unlisted()->create();

        $response = $this->getJson('/api/v1/playlists?limit=50');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($public->id, $ids);
        $this->assertNotContains($private->id, $ids);
        $this->assertNotContains($unlisted->id, $ids);
    }

    public function test_unlisted_playlist_is_reachable_by_direct_link_but_never_appears_in_the_index(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $unlisted = Playlist::factory()->ownedBy($owner)->unlisted()->create();

        // Guest: not listed, but directly reachable.
        $guestIndex = $this->getJson('/api/v1/playlists?limit=50');
        $guestIndex->assertOk();
        $this->assertNotContains($unlisted->id, collect($guestIndex->json('data'))->pluck('id')->all());

        // A different authenticated (non-owner) user does not see it either.
        Sanctum::actingAs($otherUser);
        $otherIndex = $this->getJson('/api/v1/playlists?limit=50');
        $otherIndex->assertOk();
        $this->assertNotContains($unlisted->id, collect($otherIndex->json('data'))->pluck('id')->all());

        // But GET /playlists/{id} succeeds for anyone holding the link.
        $show = $this->getJson("/api/v1/playlists/{$unlisted->id}");
        $show->assertOk();
        $show->assertJsonPath('data.id', $unlisted->id);
    }

    public function test_show_public_playlist_is_visible_to_anyone(): void
    {
        $owner = User::factory()->create();
        $public = Playlist::factory()->ownedBy($owner)->public()->create();

        $this->getJson("/api/v1/playlists/{$public->id}")->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/playlists/{$public->id}")->assertOk();
    }

    public function test_show_private_playlist_is_visible_only_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $private = Playlist::factory()->ownedBy($owner)->private()->create();

        $this->getJson("/api/v1/playlists/{$private->id}")->assertStatus(403);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/playlists/{$private->id}")->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/playlists/{$private->id}")->assertOk();
    }

    // ---------------------------------------------------------------------
    // update / destroy
    // ---------------------------------------------------------------------

    public function test_update_by_owner_succeeds_and_only_touches_submitted_fields(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->private()->create(['title' => 'Old Title']);

        Sanctum::actingAs($owner);

        $response = $this->putJson("/api/v1/playlists/{$playlist->id}", [
            'title' => 'New Title',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'New Title');
        $response->assertJsonPath('data.visibility', 'private');

        $this->assertDatabaseHas('playlists', [
            'id' => $playlist->id,
            'title' => 'New Title',
            'visibility' => 'private',
        ]);
    }

    public function test_update_by_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create(['title' => 'Original']);

        Sanctum::actingAs($stranger);

        $response = $this->putJson("/api/v1/playlists/{$playlist->id}", ['title' => 'Hijacked']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('playlists', ['id' => $playlist->id, 'title' => 'Original']);
    }

    public function test_destroy_by_owner_soft_deletes_the_playlist(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/v1/playlists/{$playlist->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('playlists', ['id' => $playlist->id]);
    }

    public function test_destroy_by_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();

        Sanctum::actingAs($stranger);

        $response = $this->deleteJson("/api/v1/playlists/{$playlist->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('playlists', ['id' => $playlist->id, 'deleted_at' => null]);
    }

    // ---------------------------------------------------------------------
    // addSong / removeSong
    // ---------------------------------------------------------------------

    public function test_add_song_by_owner_updates_tracks_count_and_total_duration(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();
        $songA = Song::factory()->create(['duration' => 200]);
        $songB = Song::factory()->create(['duration' => 300]);

        Sanctum::actingAs($owner);

        $first = $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $songA->id]);
        $first->assertCreated();
        $first->assertJsonPath('data.tracks_count', 1);
        $first->assertJsonPath('data.total_duration', 200);

        $second = $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $songB->id]);
        $second->assertCreated();
        $second->assertJsonPath('data.tracks_count', 2);
        $second->assertJsonPath('data.total_duration', 500);
    }

    public function test_adding_the_same_song_twice_is_a_conflict(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $song->id])->assertCreated();
        $response = $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $song->id]);

        $response->assertStatus(409);
    }

    public function test_add_song_by_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($stranger);

        $response = $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $song->id]);

        $response->assertStatus(403);
    }

    public function test_remove_song_by_owner_updates_tracks_count_and_total_duration(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();
        $songA = Song::factory()->create(['duration' => 200]);
        $songB = Song::factory()->create(['duration' => 300]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $songA->id])->assertCreated();
        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $songB->id])->assertCreated();

        $response = $this->deleteJson("/api/v1/playlists/{$playlist->id}/songs/{$songA->id}");
        $response->assertNoContent();

        $show = $this->getJson("/api/v1/playlists/{$playlist->id}");
        $show->assertJsonPath('data.tracks_count', 1);
        $show->assertJsonPath('data.total_duration', 300);
    }

    public function test_removing_a_song_that_is_not_in_the_playlist_is_not_found(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/v1/playlists/{$playlist->id}/songs/{$song->id}");

        $response->assertStatus(404);
    }

    public function test_remove_song_by_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $song->id])->assertCreated();

        Sanctum::actingAs($stranger);
        $response = $this->deleteJson("/api/v1/playlists/{$playlist->id}/songs/{$song->id}");

        $response->assertStatus(403);
    }

    public function test_add_song_is_rejected_once_the_playlist_reaches_its_max_tracks_cap(): void
    {
        // Lowered from the default (1000) so the cap is reachable without
        // creating a thousand songs.
        config(['music.playlists.max_tracks' => 2]);

        $owner = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($owner)->create();
        $songs = Song::factory()->count(3)->create();

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $songs[0]->id])->assertCreated();
        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $songs[1]->id])->assertCreated();

        $response = $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $songs[2]->id]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseHas('playlists', ['id' => $playlist->id, 'tracks_count' => 2]);
    }

    // ---------------------------------------------------------------------
    // IDOR: a private playlist must be completely opaque to a non-owner
    // ---------------------------------------------------------------------

    public function test_user_b_cannot_view_update_delete_or_modify_user_as_private_playlist(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $playlist = Playlist::factory()->ownedBy($userA)->private()->create();
        $song = Song::factory()->create();

        Sanctum::actingAs($userA);
        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $song->id])->assertCreated();

        Sanctum::actingAs($userB);

        $this->getJson("/api/v1/playlists/{$playlist->id}")->assertStatus(403);
        $this->putJson("/api/v1/playlists/{$playlist->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/v1/playlists/{$playlist->id}")->assertStatus(403);

        $otherSong = Song::factory()->create();
        $this->postJson("/api/v1/playlists/{$playlist->id}/songs", ['song_id' => $otherSong->id])->assertStatus(403);
        $this->deleteJson("/api/v1/playlists/{$playlist->id}/songs/{$song->id}")->assertStatus(403);

        $this->assertDatabaseHas('playlists', [
            'id' => $playlist->id,
            'deleted_at' => null,
            'title' => $playlist->title,
        ]);
        $this->assertDatabaseHas('playlist_tracks', [
            'playlist_id' => $playlist->id,
            'song_id' => $song->id,
        ]);
    }
}
