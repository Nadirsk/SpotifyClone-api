<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Providers\ProviderManager;
use App\Services\Sync\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Lazy sync (07_SYNC_ENGINE §5): a user searched for something the local
 * catalog does not have, so go and fetch it in the background.
 *
 * Dispatched after the search response has already gone out — the user gets the
 * local results immediately and the catalog is richer for whoever searches next.
 * Nothing about the HTTP response waits on a provider.
 *
 * Providers are tried in priority order and the run stops at the first one that
 * returns anything. Querying all five for the same term would multiply the API
 * spend for metadata that deduplication would mostly collapse anyway; the
 * incremental sync enriches the record from the other providers later.
 */
final class LazySyncSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 07_SYNC_ENGINE §10. */
    public int $tries = 5;

    /**
     * @param  string  $term  The search term that missed locally.
     * @param  string|null  $type  `song`, `artist` or `album`; null fetches all three.
     */
    public function __construct(
        private readonly string $term,
        private readonly ?string $type = null,
        private readonly ?int $limit = null,
    ) {
        $this->onQueue('sync');
    }

    /**
     * Shorter than the incremental jobs': a lazy sync is chasing a query a user
     * just made, so its value decays quickly and it should not sit in the queue
     * for an hour before giving up.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(ProviderManager $providers, SyncService $sync, LoggerInterface $logger): void
    {
        $term = trim($this->term);

        if ($term === '') {
            return;
        }

        $adapters = $providers->enabled();

        if ($adapters === []) {
            $logger->debug('LazySyncSearchJob: no enabled provider, nothing to fetch', ['term' => $term]);

            return;
        }

        $limit = $this->limit ?? (int) config('providers.sync.lazy_search_limit', 10);

        foreach ($adapters as $adapter) {
            $record = $providers->record($adapter->key());

            if ($record === null) {
                continue;
            }

            $synced = 0;

            if ($this->type === null || $this->type === 'artist') {
                $synced += $sync->syncArtists($record, $adapter->searchArtists($term, $limit));
            }

            if ($this->type === null || $this->type === 'album') {
                $synced += $sync->syncAlbums($record, $adapter->searchAlbums($term, $limit));
            }

            if ($this->type === null || $this->type === 'song') {
                $synced += $sync->syncSongs($record, $adapter->searchSongs($term, $limit));
            }

            if ($synced > 0) {
                $logger->info('Lazy sync populated the catalog from a search miss', [
                    'provider' => $adapter->key(),
                    'term' => $term,
                    'type' => $this->type ?? 'all',
                    'synced' => $synced,
                ]);

                // Highest-priority provider that had an answer wins; stop here.
                return;
            }
        }

        $logger->info('Lazy sync found nothing at any provider', ['term' => $term]);
    }
}
