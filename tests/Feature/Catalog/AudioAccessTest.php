<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Album;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The plan ceiling holds on *listings*, not just on `GET /songs/{id}/stream`.
 *
 * `PlaybackTest` covers the stream endpoint. This covers the hole beside it:
 * `SongResource` used to emit `songs.preview_url` verbatim, and that column holds
 * the top of the provider's ladder (`…_320.mp4`). So every album payload, search
 * result and trending shelf handed a free account a premium-quality URL, which it
 * could simply play. A ceiling enforced in one endpoint and volunteered in all
 * the others is not a ceiling, and no client-side check can repair it.
 *
 * The important assertion in each case is the *negative* one: the premium URL is
 * not merely unadvertised, it is absent from the response.
 */
final class AudioAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://aac.saavncdn.com/450/f467e05e2825cec2203546333e0d0550_320.mp4';

    private const FREE_VARIANT = 'https://aac.saavncdn.com/450/f467e05e2825cec2203546333e0d0550_96.mp4';

    protected function setUp(): void
    {
        parent::setUp();

        // Album payloads are cached by id; a warm entry from another test would
        // make this pass or fail for the wrong reason.
        Cache::flush();
    }

    private function albumWithOneTrack(): Album
    {
        $album = Album::factory()->create();

        Song::factory()->create([
            'album_id' => $album->getKey(),
            'artist_id' => $album->artist_id,
            'preview_url' => self::SOURCE,
        ]);

        return $album;
    }

    public function test_an_album_tracklist_gives_a_guest_the_free_variant_only(): void
    {
        $album = $this->albumWithOneTrack();

        $response = $this->getJson("/api/v1/albums/{$album->id}/tracks");

        $response->assertOk()->assertJsonPath('data.0.preview_url', self::FREE_VARIANT);
        $response->assertDontSee('_320.mp4');
    }

    public function test_an_album_tracklist_gives_a_free_account_the_free_variant_only(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $album = $this->albumWithOneTrack();

        $response = $this->getJson("/api/v1/albums/{$album->id}/tracks");

        $response->assertOk()->assertJsonPath('data.0.preview_url', self::FREE_VARIANT);
        $response->assertDontSee('_320.mp4');
    }

    public function test_an_album_tracklist_gives_a_premium_account_its_own_tier(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $album = $this->albumWithOneTrack();

        $this->getJson("/api/v1/albums/{$album->id}/tracks")
            ->assertOk()
            ->assertJsonPath('data.0.preview_url', self::SOURCE);
    }

    public function test_the_song_detail_payload_is_clamped_too(): void
    {
        $song = Song::factory()->create(['preview_url' => self::SOURCE]);

        $this->getJson("/api/v1/songs/{$song->id}")
            ->assertOk()
            ->assertJsonPath('data.preview_url', self::FREE_VARIANT);
    }

    public function test_a_song_listing_is_clamped_too(): void
    {
        Song::factory()->create(['preview_url' => self::SOURCE]);

        $response = $this->getJson('/api/v1/songs?limit=50');

        $response->assertOk();
        $response->assertDontSee('_320.mp4');
    }

    /**
     * The listing payload is cached per album id, and the cache holds *models*
     * rather than serialised JSON precisely so that the resource — and with it
     * the entitlement clamp — runs per request. If that ever inverted, the first
     * caller's tier would be served to everyone after them.
     */
    public function test_a_cached_album_payload_is_not_shared_across_tiers(): void
    {
        $album = $this->albumWithOneTrack();

        // Warm the cache as a guest.
        $this->getJson("/api/v1/albums/{$album->id}/tracks")
            ->assertJsonPath('data.0.preview_url', self::FREE_VARIANT);

        $premium = User::factory()->create();
        Sanctum::actingAs($premium);
        $this->postJson('/api/v1/subscription', ['plan' => 'platinum'])->assertCreated();

        $this->getJson("/api/v1/albums/{$album->id}/tracks")
            ->assertOk()
            ->assertJsonPath('data.0.preview_url', self::SOURCE);
    }

    /**
     * A URL the ladder cannot parse is returned as-is at every tier. Not a hole:
     * the ladder is *derived* from the URL's own bitrate suffix, so a URL without
     * one has no premium variant to withhold in the first place.
     */
    public function test_a_url_outside_the_provider_scheme_is_returned_untouched(): void
    {
        $url = 'https://example.test/audio/track.mp3';
        $song = Song::factory()->create(['preview_url' => $url]);

        $this->getJson("/api/v1/songs/{$song->id}")
            ->assertOk()
            ->assertJsonPath('data.preview_url', $url);
    }

    public function test_a_song_with_no_source_reports_none(): void
    {
        $song = Song::factory()->create(['preview_url' => null]);

        $this->getJson("/api/v1/songs/{$song->id}")
            ->assertOk()
            ->assertJsonPath('data.preview_url', null);
    }
}
