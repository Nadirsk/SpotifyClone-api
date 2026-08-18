<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ProviderUnavailableException;
use App\Models\ProviderArtistMapping;
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
 * Incremental artist sync. Same shape as SyncSongsJob: refresh the mappings
 * that have gone stalest, oldest first, bounded by a batch size.
 *
 * No-ops when no provider is enabled and configured.
 */
final class SyncArtistsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 07_SYNC_ENGINE §10. */
    public int $tries = 5;

    public function __construct(
        private readonly ?string $providerKey = null,
        private readonly ?int $batchSize = null,
    ) {
        $this->onQueue('sync');
    }

    /**
     * Exponential backoff in seconds; the tail exceeds the circuit breaker's
     * cooldown so a retry can actually reach a recovered provider.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(ProviderManager $providers, SyncService $sync, LoggerInterface $logger): void
    {
        $adapters = $providers->available();

        if ($this->providerKey !== null) {
            $adapters = array_values(array_filter(
                $adapters,
                fn ($adapter): bool => $adapter->key() === $this->providerKey,
            ));
        }

        if ($adapters === []) {
            $logger->debug('SyncArtistsJob: no enabled provider, nothing to sync');

            return;
        }

        $threshold = Carbon::now()->subHours((int) config('providers.sync.stale_after_hours', 24));
        $batch = $this->batchSize ?? (int) config('providers.sync.batch_size', 200);

        foreach ($adapters as $adapter) {
            $record = $providers->record($adapter->key());

            if ($record === null) {
                continue;
            }

            $mappings = ProviderArtistMapping::query()
                ->where('provider_id', $record->getKey())
                ->where(static function ($query) use ($threshold): void {
                    $query->whereNull('last_synced_at')
                        ->orWhere('last_synced_at', '<', $threshold);
                })
                ->orderBy('last_synced_at')
                ->limit($batch)
                ->get();

            $synced = 0;

            try {
                foreach ($mappings as $mapping) {
                    $data = $adapter->getArtist((string) $mapping->provider_artist_id);

                    if ($data === null) {
                        continue;
                    }

                    if ($sync->syncArtist($record, $data) !== null) {
                        $synced++;
                    }
                }
            } catch (ProviderUnavailableException $exception) {
                // Cut short, not failed — see SyncSongsJob for the reasoning.
                // Unreached mappings keep their old `last_synced_at`, so the
                // next scheduled run resumes from here.
                $logger->warning('Incremental artist sync stopped: provider unavailable', array_merge(
                    ['synced_before_stopping' => $synced],
                    $exception->context(),
                ));

                continue;
            }

            $logger->info('Incremental artist sync finished', [
                'provider' => $adapter->key(),
                'candidates' => $mappings->count(),
                'synced' => $synced,
            ]);
        }
    }
}
