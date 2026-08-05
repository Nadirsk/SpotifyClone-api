<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/v1/artists and its sub-resources (05_API_SPECIFICATION §7).
 *
 * Runs against the real, already-populated MySQL test database — see the note
 * atop SongTest for why exact-count assertions scope themselves to a fresh
 * fixture rather than assuming the catalog starts empty.
 */
final class ArtistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See SongTest::setUp — the array cache store outlives RefreshDatabase.
        Cache::flush();
    }

    public function test_index_paginates_results(): void
    {
        /*
         | RefreshDatabase truncates via migrate:fresh and does not run the
         | seeders, so the test database starts with zero artists — the total
         | is exactly what this test creates, not a pre-existing catalog.
         */
        Artist::factory()->count(7)->create();

        $response = $this->getJson('/api/v1/artists?limit=5&page=1');

        $response->assertOk()->assertJsonPath('success', true);

        $pagination = $response->json('pagination');
        $this->assertSame(5, $pagination['limit']);
        $this->assertSame(1, $pagination['page']);
        $this->assertSame(7, $pagination['total']);
        $this->assertSame(2, $pagination['last_page']);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_show_returns_artist_with_counts(): void
    {
        $artist = Artist::factory()->create();

        $response = $this->getJson("/api/v1/artists/{$artist->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $artist->id)
            ->assertJsonPath('data.name', $artist->name)
            // A bare factory artist has no albums/songs yet.
            ->assertJsonPath('data.albums_count', 0)
            ->assertJsonPath('data.songs_count', 0);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/v1/artists/'.Str::uuid()->toString());

        $response->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_albums_returns_paginated_albums_for_artist(): void
    {
        $artist = Artist::factory()->create();
        Album::factory()->count(3)->forArtist($artist)->create();

        $other = Artist::factory()->create();
        Album::factory()->forArtist($other)->create();

        $response = $this->getJson("/api/v1/artists/{$artist->id}/albums");

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(3, 'data');

        collect($response->json('data'))->each(
            fn (array $album) => $this->assertSame($artist->id, $album['artist']['id'])
        );
    }

    public function test_songs_returns_paginated_songs_for_artist(): void
    {
        $artist = Artist::factory()->create();
        Song::factory()->count(4)->create(['artist_id' => $artist->id]);

        $other = Artist::factory()->create();
        Song::factory()->create(['artist_id' => $other->id]);

        $response = $this->getJson("/api/v1/artists/{$artist->id}/songs");

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(4, 'data');

        collect($response->json('data'))->each(
            fn (array $song) => $this->assertSame($artist->id, $song['artist']['id'])
        );
    }

    public function test_related_returns_artists_sharing_a_genre(): void
    {
        $genre = Genre::factory()->create();

        $artist = Artist::factory()->create();
        Song::factory()->create(['artist_id' => $artist->id, 'genre_id' => $genre->id]);

        $related = Artist::factory()->create(['popularity' => 80]);
        Song::factory()->create(['artist_id' => $related->id, 'genre_id' => $genre->id]);

        // A fresh (default-factory) genre, guaranteed distinct from $genre.
        $unrelated = Artist::factory()->create();
        Song::factory()->create(['artist_id' => $unrelated->id]);

        $response = $this->getJson("/api/v1/artists/{$artist->id}/related");

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($related->id));
        $this->assertFalse($ids->contains($unrelated->id));
        $this->assertFalse($ids->contains($artist->id));
    }
}
