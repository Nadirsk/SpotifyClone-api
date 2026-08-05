<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Genre;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GET /api/v1/genres and GET /api/v1/languages (05_API_SPECIFICATION §16).
 *
 * Both are cached reference lists with no filtering or pagination — the whole
 * table comes back every time.
 */
final class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See SongTest::setUp — the array cache store outlives RefreshDatabase,
        // and these two endpoints are cached under the shared "song" bucket.
        Cache::flush();
    }

    public function test_genres_returns_every_genre(): void
    {
        Genre::query()->delete();
        Genre::factory()->named('Rock')->create();
        Genre::factory()->named('Jazz')->create();

        $response = $this->getJson('/api/v1/genres');

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(2, 'data');

        $names = collect($response->json('data'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Jazz', 'Rock'], $names);
    }

    public function test_languages_returns_every_language(): void
    {
        Language::query()->delete();
        Language::factory()->code('en')->create();
        Language::factory()->code('hi')->create();

        $response = $this->getJson('/api/v1/languages');

        $response->assertOk()->assertJsonPath('success', true)->assertJsonCount(2, 'data');

        $codes = collect($response->json('data'))->pluck('code')->sort()->values()->all();
        $this->assertSame(['en', 'hi'], $codes);
    }
}
