<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/v1/songs and its sub-resources (05_API_SPECIFICATION §6).
 *
 * This suite runs against real MySQL (see phpunit.xml) and the test database
 * already carries a seeded catalog (hundreds of songs/artists). Every test
 * that needs an exact count scopes itself with `artist_id` — a fresh factory
 * artist that only this test's songs point at — rather than assuming the
 * catalog starts empty.
 */
final class SongTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         | config('cache.default') is `array` under phpunit.xml, which lives
         | for the whole test process rather than being reset by
         | RefreshDatabase. Without this, a response cached by an earlier test
         | (same bucket/key, e.g. trending "songs:50") would leak into this
         | one even though the database itself has been rolled back.
         */
        Cache::flush();
    }

    public function test_index_paginates_results(): void
    {
        $artist = Artist::factory()->create();
        $genre = Genre::factory()->create();
        $language = Language::factory()->create();

        Song::factory()->count(25)->create([
            'artist_id' => $artist->id,
            'genre_id' => $genre->id,
            'language_id' => $language->id,
        ]);

        $response = $this->getJson("/api/v1/songs?artist_id={$artist->id}&limit=10&page=2");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.limit', 10)
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonCount(10, 'data');
    }

    public function test_index_filters_by_genre(): void
    {
        $artist = Artist::factory()->create();
        $language = Language::factory()->create();
        $genre = Genre::factory()->named('Zzztestgenre')->create();
        $otherGenre = Genre::factory()->create();

        Song::factory()->count(2)->create([
            'artist_id' => $artist->id,
            'genre_id' => $genre->id,
            'language_id' => $language->id,
        ]);
        Song::factory()->create([
            'artist_id' => $artist->id,
            'genre_id' => $otherGenre->id,
            'language_id' => $language->id,
        ]);

        $response = $this->getJson("/api/v1/songs?artist_id={$artist->id}&genre={$genre->slug}");

        $response->assertOk()->assertJsonCount(2, 'data');

        collect($response->json('data'))->each(
            fn (array $song) => $this->assertSame('Zzztestgenre', $song['genre']['name'])
        );
    }

    public function test_index_filters_by_language(): void
    {
        $artist = Artist::factory()->create();
        // `it` is unused by the seeded reference data (verified against the
        // fixed LanguageFactory pool), so creating it here cannot collide with
        // the `code` unique constraint.
        $language = Language::factory()->code('it')->create();
        $otherLanguage = Language::factory()->create();

        Song::factory()->count(2)->create([
            'artist_id' => $artist->id,
            'language_id' => $language->id,
        ]);
        Song::factory()->create([
            'artist_id' => $artist->id,
            'language_id' => $otherLanguage->id,
        ]);

        $response = $this->getJson("/api/v1/songs?artist_id={$artist->id}&language=it");

        $response->assertOk()->assertJsonCount(2, 'data');

        // SongResource exposes the language's name, not its code — `it` maps
        // to "Italian" in LanguageFactory's fixed code pool.
        collect($response->json('data'))->each(
            fn (array $song) => $this->assertSame('Italian', $song['language']['name'])
        );
    }

    public function test_index_filters_by_release_year(): void
    {
        $artist = Artist::factory()->create();

        Song::factory()->count(2)->create([
            'artist_id' => $artist->id,
            'release_date' => '2020-05-01',
        ]);
        Song::factory()->create([
            'artist_id' => $artist->id,
            'release_date' => '2021-05-01',
        ]);

        $response = $this->getJson("/api/v1/songs?artist_id={$artist->id}&release_year=2020");

        $response->assertOk()->assertJsonCount(2, 'data');

        collect($response->json('data'))->each(
            fn (array $song) => $this->assertStringStartsWith('2020', (string) $song['release_date'])
        );
    }

    public function test_index_filters_by_duration_range(): void
    {
        $artist = Artist::factory()->create();

        Song::factory()->create(['artist_id' => $artist->id, 'duration' => 180]);
        Song::factory()->create(['artist_id' => $artist->id, 'duration' => 200]);
        Song::factory()->create(['artist_id' => $artist->id, 'duration' => 340]);

        $response = $this->getJson("/api/v1/songs?artist_id={$artist->id}&min_duration=170&max_duration=210");

        $response->assertOk()->assertJsonCount(2, 'data');

        collect($response->json('data'))->each(
            fn (array $song) => $this->assertTrue($song['duration'] >= 170 && $song['duration'] <= 210)
        );
    }

    public function test_show_returns_song(): void
    {
        $song = Song::factory()->create();

        $response = $this->getJson("/api/v1/songs/{$song->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $song->id)
            ->assertJsonPath('data.title', $song->title);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/v1/songs/'.Str::uuid()->toString());

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Resource not found');
    }

    public function test_related_returns_songs_by_same_artist(): void
    {
        $artist = Artist::factory()->create();

        // Fresh genre/language per song (the factory default) means neither
        // shares both facets with anyone else, so the repository's
        // taxonomy-based match is empty and it falls back to "same artist".
        $song = Song::factory()->create(['artist_id' => $artist->id, 'popularity' => 10]);
        $sibling = Song::factory()->create(['artist_id' => $artist->id, 'popularity' => 90]);

        $response = $this->getJson("/api/v1/songs/{$song->id}/related");

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($sibling->id));
        $this->assertFalse($ids->contains($song->id));
    }

    public function test_trending_orders_by_trending_score_desc(): void
    {
        // The seeded catalog already has trending_score values in it, so the
        // new rows must sit strictly above the current ceiling to guarantee
        // they land in a small top-N slice deterministically.
        $ceiling = (int) (Song::query()->max('trending_score') ?? 0);

        $low = Song::factory()->create(['trending_score' => $ceiling + 100]);
        $high = Song::factory()->create(['trending_score' => $ceiling + 900]);
        $mid = Song::factory()->create(['trending_score' => $ceiling + 500]);

        $response = $this->getJson('/api/v1/songs/trending?limit=3');

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$high->id, $mid->id, $low->id], $ids);
    }

    public function test_preview_returns_preview_url_without_proxying_audio(): void
    {
        $song = Song::factory()->create([
            'preview_url' => 'https://previews.example.test/abc.mp3',
            'external_url' => 'https://listen.example.test/track/abc',
        ]);

        $response = $this->getJson("/api/v1/songs/{$song->id}/preview");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $song->id)
            ->assertJsonPath('data.preview_url', 'https://previews.example.test/abc.mp3')
            ->assertJsonPath('data.external_url', 'https://listen.example.test/track/abc')
            ->assertJsonStructure(['data' => ['id', 'title', 'duration', 'preview_url', 'external_url']]);

        // The payload is a plain JSON link, never a streamed/proxied audio body.
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }
}
