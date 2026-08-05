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

    private function makeUser(string $id): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    private function makePlaylist(string $ownerId, PlaylistVisibility $visibility): Playlist
    {
        $playlist = new Playlist;
        $playlist->user_id = $ownerId;
        $playlist->visibility = $visibility;

        return $playlist;
    }
}
