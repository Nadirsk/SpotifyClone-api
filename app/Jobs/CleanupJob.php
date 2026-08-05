<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Daily housekeeping (07_SYNC_ENGINE §9): prune data that only ever grows.
 *
 * Deletes in chunks rather than one statement, so a `history` table with years
 * of rows never holds a single multi-million-row transaction open — each chunk
 * commits and releases its locks before the next one starts.
 *
 * `search_history` has no retention setting of its own; it reuses
 * `music.history.retention_days` rather than inventing a second config key for
 * what is, for this MVP, the same policy.
 */
final class CleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Rows removed per DELETE, so one run never holds a long-lived transaction. */
    private const CHUNK_SIZE = 1000;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(LoggerInterface $logger): void
    {
        $retentionDays = max(1, (int) config('music.history.retention_days', 365));
        $cutoff = Carbon::now()->subDays($retentionDays);

        $historyDeleted = $this->pruneInChunks('history', 'played_at', $cutoff);
        $searchHistoryDeleted = $this->pruneInChunks('search_history', 'searched_at', $cutoff);

        $logger->info('Cleanup finished', [
            'retention_days' => $retentionDays,
            'history_deleted' => $historyDeleted,
            'search_history_deleted' => $searchHistoryDeleted,
        ]);
    }

    private function pruneInChunks(string $table, string $column, Carbon $cutoff): int
    {
        $deleted = 0;

        do {
            /*
             | ORDER BY + LIMIT keeps each statement's lock footprint to
             | CHUNK_SIZE rows regardless of how far behind the cutoff has
             | drifted, at the cost of one extra index scan per chunk versus a
             | single unbounded DELETE.
             */
            $affected = DB::table($table)
                ->where($column, '<', $cutoff)
                ->orderBy($column)
                ->limit(self::CHUNK_SIZE)
                ->delete();

            $deleted += $affected;
        } while ($affected === self::CHUNK_SIZE);

        return $deleted;
    }
}
