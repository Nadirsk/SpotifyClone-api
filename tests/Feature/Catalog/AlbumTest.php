<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/v1/albums and its sub-resources (05_API_SPECIFICATION §8).
 *
 * Runs against the real, already-populated MySQL test database — see the note
 * atop SongTest for why exact-count assertions scope themselves to a fresh
 * fixture rather than assuming the catalog starts empty.
 */
final class AlbumTest extends TestCase
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
        $artist = Artist::factory()->create();
        Album::factory()->count(3)->forArtist($artist)->create();

        $response = $this->getJson("/api/v1/albums?artist_id={$artist->id}&limit=2&page=1");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_show_returns_album_with_tracks(): void
    {
        $artist = Artist::factory()->create();
        $album = Album::factory()->forArtist($artist)->create();

        Song::factory()->onAlbum($album, 2)->create(['title' => 'Second Track']);
        Song::factory()->onAlbum($album, 1)->create(['title' => 'First Track']);

        $response = $this->getJson("/api/v1/albums/{$album->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $album->id)
            ->assertJsonPath('data.title', $album->title)
            ->assertJsonPath('data.artist.id', $artist->id)
            ->assertJsonCount(2, 'data.tracks');
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/v1/albums/'.Str::uuid()->toString());

        $response->assertNotFound()->assertJsonPath('success', false);
    }

    /**
     * EloquentSongRepository::forAlbum() orders by track_number, nulls last,
     * then by insertion order — this is the running order a client renders as
     * the track list.
     */
    public function test_tracks_are_ordered_by_track_number_with_nulls_last(): void
    {
        $album = Album::factory()->create();

        $third = Song::factory()->onAlbum($album, 3)->create(['title' => 'Track Three']);
        $first = Song::factory()->onAlbum($album, 1)->create(['title' => 'Track One']);
        $noNumber = Song::factory()->onAlbum($album, null)->create(['title' => 'Track Unknown']);
        $second = Song::factory()->onAlbum($album, 2)->create(['title' => 'Track Two']);

        $response = $this->getJson("/api/v1/albums/{$album->id}/tracks");

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(4, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame(
            [$first->id, $second->id, $third->id, $noNumber->id],
            $ids,
        );
    }
}
