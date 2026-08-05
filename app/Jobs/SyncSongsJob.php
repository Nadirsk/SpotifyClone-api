<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ProviderSongMapping;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Incremental song sync (07_SYNC_ENGINE §5).
 *
 * No provider offers a "changed since" cursor for arbitrary catalog records, so
 * freshness is driven off our own mapping rows: refresh the ones untouched
 * longest, oldest first, capped at a batch size. Every run therefore does a
 * bounded amount of work regardless of catalog size, and the whole catalog
 * rotates through over successive runs.
 *
 * With no provider enabled — the state of a fresh checkout — this finds nothing
 * to iterate and returns without making a request. That is a no-op, not a
 * failure (docs/DEFERRED.md §4).
 */
final class SyncSongsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 07_SYNC_ENGINE §10: at most five attempts, then the dead-letter queue. */
    public int $tries = 5;

    /**
     * @param  string|null  $providerKey  Limit the run to one provider; null syncs every enabled one.
     */
    public function __construct(
        private readonly ?string $providerKey = null,
        private readonly ?int $batchSize = null,
    ) {
        $this->onQueue('sync');
    }

    /**
     * Exponential backoff between attempts, in seconds. Starts long enough to
     * outlast a brief provider blip and ends past the circuit breaker's default
     * cooldown, so the last attempt meets a provider that has had time to recover.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(ProviderManager $providers, SyncService $sync, LoggerInterface $logger): void
    {
        $adapters = $providers->enabled();

        if ($this->providerKey !== null) {
            $adapters = array_values(array_filter(
                $adapters,
                fn ($adapter): bool => $adapter->key() === $this->providerKey,
            ));
        }

        if ($adapters === []) {
            $logger->debug('SyncSongsJob: no enabled provider, nothing to sync');

            return;
        }

        $threshold = Carbon::now()->subHours((int) config('providers.sync.stale_after_hours', 24));
        $batch = $this->batchSize ?? (int) config('providers.sync.batch_size', 200);

        foreach ($adapters as $adapter) {
            $record = $providers->record($adapter->key());

            if ($record === null) {
                continue;
            }

            $mappings = ProviderSongMapping::query()
                ->where('provider_id', $record->getKey())
                ->where(static function ($query) use ($threshold): void {
                    $query->whereNull('last_synced_at')
                        ->orWhere('last_synced_at', '<', $threshold);
                })
                // Never-synced rows sort first (MySQL orders NULL before values ascending).
                ->orderBy('last_synced_at')
                ->limit($batch)
                ->get();

            $synced = 0;

            foreach ($mappings as $mapping) {
                $data = $adapter->getSong((string) $mapping->provider_song_id);

                if ($data === null) {
                    // Adapter already logged the cause; the mapping keeps its old
                    // timestamp so the next run picks it up again.
                    continue;
                }

                if ($sync->syncSong($record, $data) !== null) {
                    $synced++;
                }
            }

            $record->forceFill(['last_synced_at' => Carbon::now()])->save();

            $logger->info('Incremental song sync finished', [
                'provider' => $adapter->key(),
                'candidates' => $mappings->count(),
                'synced' => $synced,
            ]);
        }
    }
}
