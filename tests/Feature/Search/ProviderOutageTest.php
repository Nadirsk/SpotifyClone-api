<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Provider;
use App\Models\Song;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The containment guarantee: a metadata provider having a bad day must not be
 * something a listener can perceive.
 *
 * The provider is reachable from exactly one request path — a search may sync
 * inline before answering — and this pins what happens on that path when the
 * provider refuses. Everything else the player does (playing a track, opening
 * an album, browsing trending) reads the local database and never had a
 * provider on it in the first place.
 *
 * Uses DatabaseMigrations for the same FULLTEXT-commit reason as
 * {@see SearchTest}.
 */
final class ProviderOutageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Sleep::fake();

        config([
            'providers.jiosaavn.enabled' => true,
            'providers.jiosaavn.base_url' => 'https://provider.test/api',
            'providers.jiosaavn.rate_limit.max_wait_ms' => 2_000,
            'providers.jiosaavn.rate_limit.cooldown_ms' => 60_000,
        ]);

        /*
         | DatabaseMigrations runs `migrate:fresh` without seeding, so the
         | `providers` table is empty here — ProviderSeeder only runs via
         | DatabaseSeeder. The row has to be created, not merely enabled:
         | ProviderManager gates on it, so without one every assertion below
         | would pass for the wrong reason ("no provider configured" looks
         | identical to "the provider refused us").
         */
        Provider::query()->updateOrCreate(
            ['api_name' => 'jiosaavn'],
            ['name' => 'JioSaavn', 'enabled' => true, 'priority' => 6],
        );
    }

    /** Exactly what the exhausted public wrapper returns. */
    private function fakeOutage(): void
    {
        Http::fake(['*' => Http::response('error code: 1027', 429)]);
    }

    public function test_a_provider_outage_still_answers_from_the_local_catalog(): void
    {
        Song::factory()->create(['title' => 'Zzzsearchable Odyssey']);
        $this->fakeOutage();

        $response = $this->getJson('/api/v1/search?q=Zzzsearchable');

        // Not a 500, not a 503, not an empty list: the catalog knows this song
        // and the provider was never needed to say so.
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.songs.0.title', 'Zzzsearchable Odyssey');
    }

    public function test_the_response_admits_it_is_degraded(): void
    {
        Song::factory()->create(['title' => 'Zzzsearchable Odyssey']);
        $this->fakeOutage();

        // The first search finds the provider available, tries it, is refused,
        // and parks it — so by the time the envelope is built we know we are
        // serving a partial answer, and say so.
        $response = $this->getJson('/api/v1/search?q=Zzzsearchable');

        $response->assertOk()
            ->assertJsonPath('meta.degraded', true)
            ->assertJsonPath('meta.reason', 'catalog_sync_unavailable');
    }

    public function test_a_healthy_provider_leaves_no_degradation_marker(): void
    {
        Song::factory()->create(['title' => 'Zzzsearchable Odyssey']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['total' => 0, 'results' => []]])]);

        $response = $this->getJson('/api/v1/search?q=Zzzsearchable');

        // Absent rather than false, so a client can treat its presence as the
        // whole signal.
        $response->assertOk()->assertJsonMissingPath('meta');
    }

    public function test_a_typed_search_is_contained_the_same_way(): void
    {
        Song::factory()->create(['title' => 'Zzzsearchable Odyssey']);
        $this->fakeOutage();

        $response = $this->getJson('/api/v1/search?q=Zzzsearchable&type=song');

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Zzzsearchable Odyssey')
            ->assertJsonPath('meta.degraded', true);
    }

    public function test_an_outage_does_not_burn_the_terms_resync_debounce(): void
    {
        Song::factory()->create(['title' => 'Zzzsearchable Odyssey']);
        $this->fakeOutage();

        // First search: parks the provider after one refusal.
        $this->getJson('/api/v1/search?q=Zzzsearchable')->assertOk();
        Http::assertSentCount(1);

        // Second search while parked: no outbound call, and — the point — no
        // debounce slot claimed either, since nothing was asked of the
        // provider. Otherwise this term would be locked out of syncing for 15
        // minutes after recovery, having never once reached the provider.
        $this->getJson('/api/v1/search?q=Zzzsearchable')->assertOk();
        Http::assertSentCount(1);

        $this->assertFalse(
            Cache::has('providers:lazy_sync_debounce:all:'.hash('xxh128', 'zzzsearchable')),
        );
    }
}
