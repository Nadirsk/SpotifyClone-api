<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GET /api/v1/trending/{songs,artists,albums} (05_API_SPECIFICATION §13).
 *
 * Same shape for all three types, so each is asserted with the same pattern:
 * every score sits above the seeded catalog's current ceiling, guaranteeing a
 * deterministic top-N ordering regardless of what else is in the database.
 */
final class TrendingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_trending_songs_orders_by_trending_score_desc(): void
    {
        $ceiling = (int) (Song::query()->max('trending_score') ?? 0);

        $low = Song::factory()->create(['trending_score' => $ceiling + 100]);
        $high = Song::factory()->create(['trending_score' => $ceiling + 900]);

        $response = $this->getJson('/api/v1/trending/songs?limit=2');

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$high->id, $low->id], $ids);
    }

    public function test_trending_artists_orders_by_trending_score_desc(): void
    {
        $ceiling = (int) (Artist::query()->max('trending_score') ?? 0);

        $low = Artist::factory()->create(['trending_score' => $ceiling + 100]);
        $high = Artist::factory()->create(['trending_score' => $ceiling + 900]);

        $response = $this->getJson('/api/v1/trending/artists?limit=2');

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$high->id, $low->id], $ids);
    }

    public function test_trending_albums_orders_by_trending_score_desc(): void
    {
        $ceiling = (int) (Album::query()->max('trending_score') ?? 0);

        $low = Album::factory()->create(['trending_score' => $ceiling + 100]);
        $high = Album::factory()->create(['trending_score' => $ceiling + 900]);

        $response = $this->getJson('/api/v1/trending/albums?limit=2');

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$high->id, $low->id], $ids);
    }
}
