<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\PlaylistVisibility;
use App\Models\Playlist;
use App\Models\User;
use App\Policies\PlaylistPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of every branch in PlaylistPolicy, without HTTP or the
 * database: the policy only ever compares two in-memory scalars (visibility,
 * user_id), so plain, unpersisted models are enough to exercise it.
 */
final class PlaylistPolicyTest extends TestCase
{
    private PlaylistPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new PlaylistPolicy;
    }

    // ---------------------------------------------------------------------
    // view()
    // ---------------------------------------------------------------------

    public function test_view_allows_anyone_including_guests_to_see_a_public_playlist(): void
    {
        $owner = $this->makeUser('owner-public');
        $stranger = $this->makeUser('stranger-public');
        $playlist = $this->makePlaylist($owner->id, PlaylistVisibility::Public);

        $this->assertTrue($this->policy->view(null, $playlist));
        $this->assertTrue($this->policy->view($owner, $playlist));
        $this->assertTrue($this->policy->view($stranger, $playlist));
    }

    public function test_view_allows_anyone_including_guests_to_see_an_unlisted_playlist(): void
    {
        $owner = $this->makeUser('owner-unlisted');
        $stranger = $this->makeUser('stranger-unlisted');
        $playlist = $this->makePlaylist($owner->id, PlaylistVisibility::Unlisted);

        $this->assertTrue($this->policy->view(null, $playlist));
        $this->assertTrue($this->policy->view($owner, $playlist));
        $this->assertTrue($this->policy->view($stranger, $playlist));
    }

    public function test_view_allows_only_the_owner_to_see_a_private_playlist(): void
    {
        $owner = $this->makeUser('owner-private');
        $stranger = $this->makeUser('stranger-private');
        $playlist = $this->makePlaylist($owner->id, PlaylistVisibility::Private);

        $this->assertTrue($this->policy->view($owner, $playlist));
        $this->assertFalse($this->policy->view(null, $playlist));
        $this->assertFalse($this->policy->view($stranger, $playlist));
    }

    public function test_view_allows_an_active_collaborator_to_see_a_private_playlist(): void
    {
        $owner = $this->makeUser('owner-private-collab');
        $collaborator = $this->makeUser('collaborator-private');
        $playlist = $this->makePlaylist($owner->id, PlaylistVisibility::Private);

        $this->assertTrue($this->policy->view($collaborator, $playlist, isCollaborator: true));
        $this->assertFalse($this->policy->view($collaborator, $playlist, isCollaborator: false));
    }

    // ---------------------------------------------------------------------
    // update()
    // ---------------------------------------------------------------------

    public function test_update_allows_only_the_owner_regardless_of_visibility(): void
    {
        $owner = $this->makeUser('owner-update');
        $stranger = $this->makeUser('stranger-update');

        foreach (PlaylistVisibility::cases() as $visibility) {
            $playlist = $this->makePlaylist($owner->id, $visibility);

            $this->assertTrue($this->policy->update($owner, $playlist), $visibility->value);
            $this->assertFalse($this->policy->update($stranger, $playlist), $visibility->value);
        }
    }

    // ---------------------------------------------------------------------
    // delete()
    // ---------------------------------------------------------------------

    public function test_delete_allows_only_the_owner_regardless_of_visibility(): void
    {
        $owner = $this->makeUser('owner-delete');
        $stranger = $this->makeUser('stranger-delete');

        foreach (PlaylistVisibility::cases() as $visibility) {
            $playlist = $this->makePlaylist($owner->id, $visibility);

            $this->assertTrue($this->policy->delete($owner, $playlist), $visibility->value);
            $this->assertFalse($this->policy->delete($stranger, $playlist), $visibility->value);
        }
    }

    // ---------------------------------------------------------------------
    // addSong()
    // ---------------------------------------------------------------------

    public function test_add_song_allows_only_the_owner_regardless_of_visibility(): void
    {
        $owner = $this->makeUser('owner-add-song');
        $stranger = $this->makeUser('stranger-add-song');

        foreach (PlaylistVisibility::cases() as $visibility) {
            $playlist = $this->makePlaylist($owner->id, $visibility);

            $this->assertTrue($this->policy->addSong($owner, $playlist), $visibility->value);
            $this->assertFalse($this->policy->addSong($stranger, $playlist), $visibility->value);
        }
    }

    public function test_add_song_allows_a_collaborator_only_while_the_playlist_is_collaborative(): void
    {
        $owner = $this->makeUser('owner-add-song-collab');
        $collaborator = $this->makeUser('collaborator-add-song');

        $collaborative = $this->makePlaylist($owner->id, PlaylistVisibility::Public, isCollaborative: true);
        $this->assertTrue($this->policy->addSong($collaborator, $collaborative, isCollaborator: true));
        $this->assertFalse($this->policy->addSong($collaborator, $collaborative, isCollaborator: false));

        $notCollaborative = $this->makePlaylist($owner->id, PlaylistVisibility::Public, isCollaborative: false);
        $this->assertFalse($this->policy->addSong($collaborator, $notCollaborative, isCollaborator: true));
    }

    // ---------------------------------------------------------------------
    // removeSong()
    // ---------------------------------------------------------------------

    public function test_remove_song_allows_only_the_owner_regardless_of_visibility(): void
    {
        $owner = $this->makeUser('owner-remove-song');
        $stranger = $this->makeUser('stranger-remove-song');

        foreach (PlaylistVisibility::cases() as $visibility) {
            $playlist = $this->makePlaylist($owner->id, $visibility);

            $this->assertTrue($this->policy->removeSong($owner, $playlist), $visibility->value);
            $this->assertFalse($this->policy->removeSong($stranger, $playlist), $visibility->value);
        }
    }

    public function test_remove_song_allows_a_collaborator_only_while_the_playlist_is_collaborative(): void
    {
        $owner = $this->makeUser('owner-remove-song-collab');
        $collaborator = $this->makeUser('collaborator-remove-song');

        $collaborative = $this->makePlaylist($owner->id, PlaylistVisibility::Public, isCollaborative: true);
        $this->assertTrue($this->policy->removeSong($collaborator, $collaborative, isCollaborator: true));
        $this->assertFalse($this->policy->removeSong($collaborator, $collaborative, isCollaborator: false));

        $notCollaborative = $this->makePlaylist($owner->id, PlaylistVisibility::Public, isCollaborative: false);
        $this->assertFalse($this->policy->removeSong($collaborator, $notCollaborative, isCollaborator: true));
    }

    // ---------------------------------------------------------------------
    // inviteCollaborators() / removeCollaborator()
    // ---------------------------------------------------------------------

    public function test_invite_collaborators_and_remove_collaborator_allow_only_the_owner(): void
    {
        $owner = $this->makeUser('owner-invite');
        $stranger = $this->makeUser('stranger-invite');
        $playlist = $this->makePlaylist($owner->id, PlaylistVisibility::Private);

        $this->assertTrue($this->policy->inviteCollaborators($owner, $playlist));
        $this->assertFalse($this->policy->inviteCollaborators($stranger, $playlist));

        $this->assertTrue($this->policy->removeCollaborator($owner, $playlist));
        $this->assertFalse($this->policy->removeCollaborator($stranger, $playlist));
    }

    // ---------------------------------------------------------------------
    // leave()
    // ---------------------------------------------------------------------

    public function test_leave_allows_an_active_collaborator_but_never_the_owner(): void
    {
        $owner = $this->makeUser('owner-leave');
        $collaborator = $this->makeUser('collaborator-leave');
        $playlist = $this->makePlaylist($owner->id, PlaylistVisibility::Private);

        $this->assertTrue($this->policy->leave($collaborator, $playlist, isCollaborator: true));
        $this->assertFalse($this->policy->leave($collaborator, $playlist, isCollaborator: false));
        // The owner is never a collaborator, so this is false regardless of the flag.
        $this->assertFalse($this->policy->leave($owner, $playlist, isCollaborator: true));
    }

    private function makeUser(string $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    private function makePlaylist(string $ownerId, PlaylistVisibility $visibility, bool $isCollaborative = false): Playlist
    {
        $playlist = new Playlist;
        $playlist->user_id = $ownerId;
        $playlist->visibility = $visibility;
        $playlist->is_collaborative = $isCollaborative;

        return $playlist;
    }
}
