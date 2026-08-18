<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Providers\SupportsPlaylists;
use App\Exceptions\ProviderUnavailableException;
use App\Models\ProviderPlaylistMapping;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\PlaylistSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Incremental playlist refresh, the fourth sibling of
 * {@see SyncSongsJob}/{@see SyncArtistsJob}/{@see SyncAlbumsJob}.
 *
 * Same contract as those three: no provider offers a "changed since" cursor,
 * so freshness is driven off our own mapping rows — refresh the ones untouched
 * longest, oldest first, capped at a batch size, so every run does a bounded
 * amount of work regardless of catalog size.
 *
 * Playlists need this more than the other three do. A song's metadata is
 * essentially static once written, but an editorial playlist is *defined* by
 * its tracklist and is re-curated constantly — tracks added, dropped and
 * reordered — so a playlist that is not refreshed is wrong rather than merely
 * stale. Only the first page is pulled here, which is where new tracks land;
 * the crawl frontier's slow revisit re-walks the whole thing.
 */
final class SyncPlaylistsJob implements ShouldQueue
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

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(
        ProviderManager $providers,
        PlaylistSyncService $playlists,
        LoggerInterface $logger,
    ): void {
        $adapters = $providers->available();

        if ($this->providerKey !== null) {
            $adapters = array_values(array_filter(
                $adapters,
                fn ($adapter): bool => $adapter->key() === $this->providerKey,
            ));
        }

        $threshold = Carbon::now()->subHours((int) config('providers.sync.stale_after_hours', 24));
        $batch = $this->batchSize ?? (int) config('providers.sync.batch_size', 200);

        foreach ($adapters as $adapter) {
            // Not every provider has playlists; skip rather than reflecting.
            if (! $adapter instanceof SupportsPlaylists) {
                continue;
            }

            $record = $providers->record($adapter->key());

            if ($record === null) {
                continue;
            }

            $mappings = ProviderPlaylistMapping::query()
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

            try {
                foreach ($mappings as $mapping) {
                    $data = $adapter->getPlaylist((string) $mapping->provider_playlist_id);

                    if ($data === null) {
                        // The provider answered and no longer has this playlist.
                        // Its timestamp is left alone so the next run retries it.
                        continue;
                    }

                    /*
                     | Checksum short-circuit, matching SyncService: the
                     | tracklist is folded into ProviderPlaylistData::checksum(),
                     | so an unchanged playlist skips the whole rewrite —
                     | track deletion, re-insert and counter recount — and only
                     | moves its freshness marker.
                     */
                    if ($mapping->checksum === $data->checksum()) {
                        $mapping->forceFill(['last_synced_at' => Carbon::now()])->saveQuietly();

                        continue;
                    }

                    if ($playlists->sync($record, $data, replaceTracks: true) !== null) {
                        $synced++;
                    }
                }
            } catch (ProviderUnavailableException $exception) {
                /*
                 | Cut short, not failed — same reasoning as the other
                 | incremental jobs. Everything refreshed so far is committed,
                 | unreached mappings kept their old timestamp, and the schedule
                 | is the retry.
                 */
                $logger->warning('Incremental playlist sync stopped: provider unavailable', array_merge(
                    ['synced_before_stopping' => $synced],
                    $exception->context(),
                ));

                continue;
            }

            $logger->info('Incremental playlist sync finished', [
                'provider' => $adapter->key(),
                'candidates' => $mappings->count(),
                'synced' => $synced,
            ]);
        }
    }
}
