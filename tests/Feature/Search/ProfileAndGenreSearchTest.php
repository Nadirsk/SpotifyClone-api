<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\PlaylistVisibility;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * The two search types added so the Profiles and Genres tabs stop being
 * dead ends, plus the playlist type the Playlists tab never actually called.
 *
 * `DatabaseMigrations`, not `RefreshDatabase` — for exactly the reason
 * `SearchTest` documents at length: InnoDB only flushes new rows into a
 * FULLTEXT index on COMMIT, and `RefreshDatabase`'s per-test transaction never
 * commits, so `MATCH … AGAINST` cannot see anything a test just created.
 */
final class ProfileAndGenreSearchTest extends TestCase
{
    use DatabaseMigrations;

    public function test_profiles_are_searchable_by_name(): void
    {
        User::factory()->create(['name' => 'Priya Sharma']);
        User::factory()->create(['name' => 'Unrelated Person']);

        $response = $this->getJson('/api/v1/search?q=Priya&type=user')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Priya Sharma', $response->json('data.0.name'));
    }

    public function test_profile_search_never_returns_an_email_address(): void
    {
        User::factory()->create(['name' => 'Priya Sharma', 'email' => 'priya@example.test']);

        $row = $this->getJson('/api/v1/search?q=Priya&type=user')->assertOk()->json('data.0');

        // PublicUserResource, not UserResource — see SearchController::RESOURCES.
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayHasKey('followers_count', $row);
    }

    public function test_genres_are_searchable_by_name(): void
    {
        Genre::factory()->create(['name' => 'Punjabi Pop', 'slug' => 'punjabi-pop']);
        Genre::factory()->create(['name' => 'Classical', 'slug' => 'classical']);

        $response = $this->getJson('/api/v1/search?q=Punjabi&type=genre')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('punjabi-pop', $response->json('data.0.slug'));
    }

    public function test_playlist_search_returns_public_playlists(): void
    {
        Playlist::factory()->create([
            'title' => 'Road Trip Anthems',
            'visibility' => PlaylistVisibility::Public,
        ]);

        $response = $this->getJson('/api/v1/search?q=Road&type=playlist')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Road Trip Anthems', $response->json('data.0.title'));
    }

    public function test_playlist_search_hides_private_and_unlisted_playlists(): void
    {
        Playlist::factory()->create(['title' => 'Road Secret', 'visibility' => PlaylistVisibility::Private]);
        Playlist::factory()->create(['title' => 'Road Hidden', 'visibility' => PlaylistVisibility::Unlisted]);

        $this->getJson('/api/v1/search?q=Road&type=playlist')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_grouped_response_carries_the_new_types(): void
    {
        User::factory()->create(['name' => 'Rahul Verma']);
        Genre::factory()->create(['name' => 'Rahul Beats', 'slug' => 'rahul-beats']);

        $data = $this->getJson('/api/v1/search?q=Rahul')->assertOk()->json('data');

        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('genres', $data);
        $this->assertCount(1, $data['users']);
        $this->assertCount(1, $data['genres']);
    }

    public function test_an_unknown_type_is_still_rejected(): void
    {
        $this->getJson('/api/v1/search?q=anything&type=nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }
}
