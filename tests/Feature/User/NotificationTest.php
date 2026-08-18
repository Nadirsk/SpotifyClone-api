<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\Playlist;
use App\Models\User;
use App\Notifications\UserFollowedYou;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_index_returns_only_the_callers_own_notifications(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();
        $follower = User::factory()->create();

        $me->notify(new UserFollowedYou($follower));
        $someoneElse->notify(new UserFollowedYou($follower));

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.category', 'follow');
    }

    public function test_the_payload_is_flattened_and_never_leaks_the_php_class_name(): void
    {
        $me = User::factory()->create(['name' => 'Me']);
        $follower = User::factory()->create(['name' => 'Priya']);

        $me->notify(new UserFollowedYou($follower));
        Sanctum::actingAs($me);

        $row = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');

        $this->assertSame('Priya started following you', $row['title']);
        $this->assertSame("/user/{$follower->id}", $row['href']);
        $this->assertFalse($row['read']);
        // `type` is a FQCN and an implementation detail — see NotificationResource.
        $this->assertArrayNotHasKey('type', $row);
    }

    public function test_unread_filter_narrows_the_list(): void
    {
        $me = User::factory()->create();
        $me->notify(new UserFollowedYou(User::factory()->create()));
        $me->notify(new UserFollowedYou(User::factory()->create()));

        Sanctum::actingAs($me);

        $first = $this->getJson('/api/v1/notifications')->json('data.0.id');
        $this->postJson("/api/v1/notifications/{$first}/read")->assertOk();

        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('unread_count', 1);
    }

    public function test_unread_count_endpoint(): void
    {
        $me = User::factory()->create();
        $me->notify(new UserFollowedYou(User::factory()->create()));

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_mark_all_read(): void
    {
        $me = User::factory()->create();
        $me->notify(new UserFollowedYou(User::factory()->create()));
        $me->notify(new UserFollowedYou(User::factory()->create()));

        Sanctum::actingAs($me);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 2);

        $this->assertSame(0, $me->unreadNotifications()->count());
    }

    public function test_another_users_notification_is_a_404_not_a_403(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $theirs->notify(new UserFollowedYou(User::factory()->create()));

        $id = $theirs->notifications()->first()?->id;

        Sanctum::actingAs($mine);

        // 404, so an id that is not yours is indistinguishable from one that
        // does not exist — see NotificationService::markRead().
        $this->postJson("/api/v1/notifications/{$id}/read")->assertStatus(404);
        $this->deleteJson("/api/v1/notifications/{$id}")->assertStatus(404);
    }

    public function test_delete_and_clear(): void
    {
        $me = User::factory()->create();
        $me->notify(new UserFollowedYou(User::factory()->create()));
        $me->notify(new UserFollowedYou(User::factory()->create()));

        Sanctum::actingAs($me);

        $id = $this->getJson('/api/v1/notifications')->json('data.0.id');

        $this->deleteJson("/api/v1/notifications/{$id}")->assertNoContent();
        $this->assertSame(1, $me->notifications()->count());

        $this->deleteJson('/api/v1/notifications')->assertNoContent();
        $this->assertSame(0, $me->notifications()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | The events that produce notifications
    |--------------------------------------------------------------------------
    */

    public function test_following_a_user_notifies_them_once_even_if_repeated(): void
    {
        $followed = User::factory()->create();
        $follower = User::factory()->create();

        Sanctum::actingAs($follower);

        $this->postJson("/api/v1/users/{$followed->id}/follow")->assertCreated();
        // Idempotent — and must not send a second notification, or the button
        // becomes an inbox-spamming device.
        $this->postJson("/api/v1/users/{$followed->id}/follow")->assertCreated();

        $this->assertSame(1, $followed->notifications()->count());
    }

    public function test_accepting_a_playlist_invite_notifies_the_owner(): void
    {
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        // `for($owner, 'owner')` — the relation is named `owner`, not `user`.
        $playlist = Playlist::factory()->for($owner, 'owner')->create(['is_collaborative' => true]);

        Sanctum::actingAs($owner);
        $token = $this->postJson("/api/v1/playlists/{$playlist->id}/invitations")
            ->assertCreated()
            ->json('data.token');

        Sanctum::actingAs($collaborator);
        $this->postJson("/api/v1/playlists/invitations/{$token}/accept")->assertOk();

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(
            'collaboration',
            $owner->notifications()->first()?->data['category'],
        );
    }
}
