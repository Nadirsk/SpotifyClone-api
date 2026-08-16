<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Stream resolution, quality tiers and the Premium download gate.
 *
 * The provider's URL scheme is what the whole quality ladder rests on — every
 * tier is derived by rewriting the bitrate suffix on the one URL that was
 * synced (see `AudioSourceResolver`). These tests pin that derivation, because
 * it is a convention rather than a contract and a silent change to it would
 * otherwise only surface as listeners hearing the wrong bitrate.
 */
final class PlaybackTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'https://aac.saavncdn.com/450/f467e05e2825cec2203546333e0d0550_320.mp4';

    private function song(?string $previewUrl = self::SOURCE): Song
    {
        return Song::factory()->create(['preview_url' => $previewUrl]);
    }

    /*
    |--------------------------------------------------------------------------
    | Streaming
    |--------------------------------------------------------------------------
    */

    public function test_a_guest_is_served_the_free_ceiling_rather_than_refused(): void
    {
        $song = $this->song();

        $this->getJson("/api/v1/songs/{$song->id}/stream?quality=very_high")
            ->assertOk()
            // Clamped down to the free tier, not honoured and not 401'd.
            ->assertJsonPath('data.quality', 'normal')
            ->assertJsonPath('data.bitrate_kbps', 96)
            ->assertJsonPath('data.max_quality', 'normal')
            ->assertJsonPath('data.url', str_replace('_320.', '_96.', self::SOURCE));
    }

    public function test_a_free_account_is_clamped_to_normal(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $song = $this->song();

        $this->getJson("/api/v1/songs/{$song->id}/stream?quality=very_high")
            ->assertOk()
            ->assertJsonPath('data.quality', 'normal');
    }

    public function test_a_premium_account_gets_the_tier_it_asks_for(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $song = $this->song();

        $this->getJson("/api/v1/songs/{$song->id}/stream?quality=very_high")
            ->assertOk()
            ->assertJsonPath('data.quality', 'very_high')
            ->assertJsonPath('data.bitrate_kbps', 320)
            ->assertJsonPath('data.variant_derived', true)
            ->assertJsonPath('data.url', self::SOURCE);
    }

    public function test_platinums_lossless_ceiling_degrades_to_the_best_variant_that_exists(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/subscription', ['plan' => 'platinum'])->assertCreated();

        $song = $this->song();

        /*
         | Platinum advertises lossless, but no provider variant serves it. The
         | response must report what was actually resolved — claiming `lossless`
         | over a 320kbps file would be the API lying to the player.
         */
        $this->getJson("/api/v1/songs/{$song->id}/stream?quality=lossless")
            ->assertOk()
            ->assertJsonPath('data.bitrate_kbps', 320)
            ->assertJsonPath('data.url', self::SOURCE);
    }

    public function test_the_saved_preference_is_used_when_no_quality_is_requested(): void
    {
        $user = User::factory()->create(['audio_quality' => 'low']);
        Sanctum::actingAs($user);

        $song = $this->song();

        $this->getJson("/api/v1/songs/{$song->id}/stream")
            ->assertOk()
            ->assertJsonPath('data.quality', 'low')
            ->assertJsonPath('data.url', str_replace('_320.', '_48.', self::SOURCE));
    }

    public function test_a_url_outside_the_known_scheme_is_returned_untouched(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $song = $this->song('https://example.test/audio/track.mp3');

        $this->getJson("/api/v1/songs/{$song->id}/stream?quality=low")
            ->assertOk()
            ->assertJsonPath('data.url', 'https://example.test/audio/track.mp3')
            // The caller must not be told it got the tier it asked for.
            ->assertJsonPath('data.variant_derived', false);
    }

    public function test_an_unrecognised_quality_parameter_is_ignored_not_rejected(): void
    {
        $song = $this->song();

        $this->getJson("/api/v1/songs/{$song->id}/stream?quality=ultra")
            ->assertOk()
            ->assertJsonPath('data.quality', 'normal');
    }

    public function test_a_song_with_no_source_is_a_404(): void
    {
        $song = $this->song(null);

        $this->getJson("/api/v1/songs/{$song->id}/stream")->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    */

    public function test_download_requires_authentication(): void
    {
        $song = $this->song();

        $this->getJson("/api/v1/songs/{$song->id}/download")->assertStatus(401);
    }

    public function test_download_is_402_for_a_free_account(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $song = $this->song();

        /*
         | 402 Payment Required, not 403: the caller is authenticated and the
         | request is well-formed. That distinction is what lets the client show
         | an upgrade prompt instead of a generic "not allowed".
         */
        $this->getJson("/api/v1/songs/{$song->id}/download")->assertStatus(402);
    }

    public function test_download_streams_the_audio_for_a_premium_account(): void
    {
        Http::fake([
            'aac.saavncdn.com/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mp4']),
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $song = $this->song();
        $song->artist->update(['name' => 'Arijit Singh']);
        $song->update(['title' => 'Gehra Hua']);

        $response = $this->get("/api/v1/songs/{$song->id}/download?quality=very_high");

        $response->assertOk();
        $response->assertHeader('X-Audio-Quality', 'very_high');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Arijit Singh - Gehra Hua', (string) $response->headers->get('Content-Disposition'));

        Http::assertSent(static fn (Request $request): bool => $request->url() === self::SOURCE);
    }

    public function test_download_asks_the_provider_for_the_clamped_tier(): void
    {
        Http::fake(['aac.saavncdn.com/*' => Http::response('bytes', 200)]);

        // Free account: even an explicit `very_high` must fetch 96kbps… except
        // it never gets that far, because the gate rejects it first. Premium
        // with a *low* preference is the case that proves the clamp is applied
        // to the fetch rather than only to the response metadata.
        $user = User::factory()->create(['audio_quality' => 'low']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $song = $this->song();

        $this->get("/api/v1/songs/{$song->id}/download")->assertOk();

        Http::assertSent(
            static fn (Request $request): bool => str_ends_with($request->url(), '_48.mp4'),
        );
    }

    public function test_download_is_404_when_the_provider_refuses(): void
    {
        Http::fake(['aac.saavncdn.com/*' => Http::response('', 404)]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/subscription', ['plan' => 'standard'])->assertCreated();

        $song = $this->song();

        $this->getJson("/api/v1/songs/{$song->id}/download")->assertStatus(404);
    }
}
