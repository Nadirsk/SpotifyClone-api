<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Artist;
use App\Models\Song;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GET /api/v1/search and GET /api/v1/search/suggest
 * (05_API_SPECIFICATION §5, 06_SEARCH_ARCHITECTURE §12–13).
 *
 * Runs against real MySQL FULLTEXT (see phpunit.xml) — DatabaseSearchEngine's
 * boolean-mode matching is exercised for real here, not stubbed. Search terms
 * are deliberately >= 3 characters: MySQL's default innodb_ft_min_token_size
 * drops shorter words from the index entirely, which is a different code path
 * (the LIKE-prefix fallback) than the one these tests are aimed at.
 *
 * Uses DatabaseMigrations, NOT RefreshDatabase. InnoDB flushes newly written
 * rows into a FULLTEXT index's on-disk structure only on COMMIT, and
 * RefreshDatabase wraps every test in a transaction it rolls back instead of
 * committing — so a row created inside such a test is invisible to
 * MATCH...AGAINST no matter how correct the search code is (confirmed by
 * running this exact suite under RefreshDatabase first: every assertion
 * against freshly-created rows failed while plain WHERE-based catalog tests
 * were unaffected). DatabaseMigrations re-runs migrate:fresh before every test
 * instead of wrapping it in a transaction, so inserts really commit and are
 * immediately searchable. It costs a full re-migration per test — acceptable
 * for this file's small number of tests.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so a zero-result search's
 * LazySyncSearchJob dispatch runs inline. With every provider disabled (the
 * seeded default) it is a safe no-op, not something these tests need to guard
 * against.
 */
final class SearchTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        // See Catalog\SongTest::setUp — the array cache store outlives RefreshDatabase.
        Cache::flush();
    }

    public function test_global_search_returns_grouped_results_across_types(): void
    {
        Song::factory()->create(['title' => 'Zzzsearchable Odyssey']);
        Artist::factory()->create(['name' => 'Zzzsearchable Collective']);

        $response = $this->getJson('/api/v1/search?q=Zzzsearchable');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['songs', 'artists', 'albums', 'playlists']]);

        $songTitles = collect($response->json('data.songs'))->pluck('title');
        $artistNames = collect($response->json('data.artists'))->pluck('name');

        $this->assertTrue($songTitles->contains('Zzzsearchable Odyssey'));
        $this->assertTrue($artistNames->contains('Zzzsearchable Collective'));
    }

    public function test_typed_search_returns_a_paginated_single_type_list(): void
    {
        Song::factory()->count(2)->create(['title' => 'Yyysearchable Voyage']);
        Artist::factory()->create(['name' => 'Yyysearchable Voyage']);

        $response = $this->getJson('/api/v1/search?q=Yyysearchable&type=song&limit=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['pagination' => ['page', 'limit', 'total', 'last_page']]);

        collect($response->json('data'))->each(
            fn (array $song) => $this->assertStringContainsString('Yyysearchable', $song['title'])
        );
    }

    public function test_missing_query_term_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/search');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('q');
    }

    public function test_unknown_type_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/search?q=anything&type=podcast');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('type');
    }

    public function test_zero_result_search_returns_200_with_empty_arrays(): void
    {
        $response = $this->getJson('/api/v1/search?q=Qqqnothingmatchesthiswhatsoever');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.songs', [])
            ->assertJsonPath('data.artists', [])
            ->assertJsonPath('data.albums', [])
            ->assertJsonPath('data.playlists', []);
    }

    public function test_suggest_returns_matching_titles(): void
    {
        Song::factory()->create(['title' => 'Xxxsearchable Horizon', 'popularity' => 90]);

        $response = $this->getJson('/api/v1/search/suggest?q=Xxxsearchable&limit=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['suggestions']]);

        $suggestions = $response->json('data.suggestions');
        $this->assertIsArray($suggestions);
        $this->assertContains('Xxxsearchable Horizon', $suggestions);
    }

    public function test_suggest_requires_a_query_term(): void
    {
        $response = $this->getJson('/api/v1/search/suggest');

        $response->assertStatus(422)->assertJsonValidationErrors('q');
    }
}
