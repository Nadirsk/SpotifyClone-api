<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\CleanupJob;
use App\Models\History;
use App\Models\SearchHistory;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * CleanupJob prunes `history` and `search_history` rows older than
 * `music.history.retention_days` (07_SYNC_ENGINE §9). search_history reuses
 * the same retention setting rather than having its own.
 */
class CleanupJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['music.history.retention_days' => 30]);
    }

    private function runJob(): void
    {
        (new CleanupJob)->handle(app(LoggerInterface::class));
    }

    public function test_history_rows_older_than_retention_are_deleted_and_recent_rows_survive(): void
    {
        $user = User::factory()->create();
        $song = Song::factory()->create();

        $stale = History::query()->create([
            'user_id' => $user->getKey(),
            'song_id' => $song->getKey(),
            'played_at' => now()->subDays(40),
            'ms_played' => 100_000,
        ]);

        $fresh = History::query()->create([
            'user_id' => $user->getKey(),
            'song_id' => $song->getKey(),
            'played_at' => now()->subDays(10),
            'ms_played' => 100_000,
        ]);

        $this->runJob();

        $this->assertSame(1, History::query()->count());
        $this->assertNull(History::query()->find($stale->getKey()));
        $this->assertNotNull(History::query()->find($fresh->getKey()));
    }

    public function test_search_history_rows_older_than_retention_are_deleted_and_recent_rows_survive(): void
    {
        // Guest searches carry a null user_id and must be prunable too.
        $stale = SearchHistory::query()->create([
            'user_id' => null,
            'keyword' => 'stale keyword',
            'results_count' => 3,
            'searched_at' => now()->subDays(45),
        ]);

        $fresh = SearchHistory::query()->create([
            'user_id' => null,
            'keyword' => 'fresh keyword',
            'results_count' => 7,
            'searched_at' => now()->subDays(1),
        ]);

        $this->runJob();

        $this->assertSame(1, SearchHistory::query()->count());
        $this->assertNull(SearchHistory::query()->find($stale->getKey()));
        $this->assertNotNull(SearchHistory::query()->find($fresh->getKey()));
    }
}
