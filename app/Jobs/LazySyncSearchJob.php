<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Cache\CacheService;
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
     * @param  string|null  $cacheKey  The `search` bucket key the miss was cached
     *   under (`SearchQuery::cacheKey()`), so a successful sync can evict the
     *   stale empty response instead of leaving it to serve for the full TTL.
     */
    public function __construct(
        private readonly string $term,
        private readonly ?string $type = null,
        private readonly ?int $limit = null,
        private readonly ?string $cacheKey = null,
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

    public function handle(ProviderManager $providers, SyncService $sync, CacheService $cache, LoggerInterface $logger): void
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
                /*
                 | Not the thin search result directly: it carries a name and a
                 | photo but never a bio or a follower count (see
                 | JioSaavnAdapter::mapArtist()'s doc on why — those fields only
                 | exist on the single-artist detail response). Upgrading each
                 | hit here means an artist a user actually searched for gets
                 | the rich record immediately rather than surfacing as a bare
                 | name until `catalog:enrich-artists` next backfills it.
                 */
                $detailed = array_map(
                    fn ($thin) => $adapter->getArtist($thin->externalId) ?? $thin,
                    $adapter->searchArtists($term, $limit),
                );

                $synced += $sync->syncArtists($record, $detailed);
            }

            if ($this->type === null || $this->type === 'album') {
                /*
                 | Not just syncAlbums(): an album entity alone carries no
                 | track list (no provider's DTO has one), so a search-
                 | discovered album otherwise sits with zero songs until
                 | one happens to also surface from an unrelated song
                 | search. Where the adapter can fetch a tracklist directly
                 | (JioSaavnAdapter::albumTracks() — not part of
                 | ProviderAdapter, see its docblock), use it so an album
                 | found this way is actually playable immediately.
                 */
                $fetchesTracks = method_exists($adapter, 'albumTracks');

                foreach ($adapter->searchAlbums($term, $limit) as $albumData) {
                    $album = $sync->syncAlbum($record, $albumData);

                    if ($album === null) {
                        continue;
                    }

                    $synced++;

                    if ($fetchesTracks) {
                        $synced += $sync->syncSongs($record, $adapter->albumTracks($albumData->externalId));
                    }
                }
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

                /*
                 | Without this, the empty response the user's own request cached
                 | (up to `CACHE_TTL_SEARCH`, 15 minutes by default) would keep
                 | being served back to them even though the catalog now has the
                 | answer — the sync would be invisible until the entry expired
                 | on its own.
                 */
                if ($this->cacheKey !== null) {
                    $cache->forget('search', $this->cacheKey);
                }

                // Highest-priority provider that had an answer wins; stop here.
                return;
            }
        }

        $logger->info('Lazy sync found nothing at any provider', ['term' => $term]);
    }
}
