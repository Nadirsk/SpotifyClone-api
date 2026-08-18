<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CrawlType;
use App\Models\Artist;
use App\Models\ProviderArtistMapping;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\CrawlFrontier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Keeps the catalog current: re-opens cheap probes so newly released music
 * lands in the database without anyone asking for it.
 *
 * This job makes no provider calls of its own. It only decides *what is worth
 * re-checking* and puts those targets back on the frontier;
 * {@see CrawlFrontierJob} does the fetching. Splitting it that way means the
 * expensive part stays under one concurrency limit and one rate limiter,
 * instead of two jobs independently hammering the provider.
 *
 * Two cadences, because "has this artist released anything" and "have we
 * walked this artist's entire discography" are very different questions:
 *
 * - **Frequent (hours).** Re-open {@see CrawlType::ArtistLatest} probes — two
 *   requests per artist against a newest-first listing. This is what actually
 *   catches a new single on the day it drops.
 * - **Slow (days).** Re-open the exhaustive walks and playlist refreshes, as a
 *   backstop for anything the cheap probe cannot see (a back-catalogue album
 *   added retroactively, a track re-licensed under a new ID).
 *
 * Artists are prioritised by popularity, because release frequency correlates
 * with it far more strongly than with anything else available here — an active
 * top-tier artist ships music constantly, while most of the long tail is
 * inactive and would waste the budget if checked at the same rate.
 */
final class DiscoverNewReleasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly ?string $providerKey = null)
    {
        $this->onQueue('sync');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ProviderManager $providers, CrawlFrontier $frontier, LoggerInterface $logger): void
    {
        $adapters = $providers->enabled();

        if ($this->providerKey !== null) {
            $adapters = array_values(array_filter(
                $adapters,
                fn ($adapter): bool => $adapter->key() === $this->providerKey,
            ));
        }

        foreach ($adapters as $adapter) {
            $provider = $adapter->key();

            $queued = $this->queueLatestProbes($frontier, $provider);
            $reopened = $this->reopenStaleWalks($frontier, $provider);

            $logger->info('New-release sweep finished', [
                'provider' => $provider,
                'latest_probes_queued' => $queued,
                'stale_walks_reopened' => $reopened,
            ]);
        }
    }

    /**
     * Ensure the most popular artists have a pending newest-first probe.
     *
     * `enqueue()` is a no-op for a target that already exists in any state, so
     * the probes that matter are the ones re-opened by
     * {@see CrawlFrontier::reopenStale()} below — this call only covers artists
     * that have never had a probe created at all (synced by lazy search, or by
     * an album's tracklist, rather than by a crawl).
     */
    private function queueLatestProbes(CrawlFrontier $frontier, string $provider): int
    {
        $batch = (int) config('providers.crawl.new_release_batch', 200);

        /*
         | Read the provider's own artist IDs off the mapping table rather than
         | the artists table: the frontier is keyed by external ID, and an
         | artist row on its own has no idea what JioSaavn calls it.
         |
         | Joined to `artists` for the popularity ordering. Both columns are
         | indexed, and the batch cap keeps this bounded as the catalog grows.
         */
        $externalIds = ProviderArtistMapping::query()
            ->join('providers', 'providers.id', '=', 'provider_artist_mappings.provider_id')
            ->join('artists', 'artists.id', '=', 'provider_artist_mappings.artist_id')
            ->where('providers.api_name', $provider)
            ->orderByDesc('artists.popularity')
            ->limit($batch)
            ->pluck('provider_artist_mappings.provider_artist_id');

        return $frontier->enqueueMany($provider, CrawlType::ArtistLatest, $externalIds);
    }

    /**
     * Re-open completed targets that are old enough to be worth another look.
     *
     * The two cadences are applied as two calls with different type sets and
     * different age thresholds — see the class docblock for why they differ.
     */
    private function reopenStaleWalks(CrawlFrontier $frontier, string $provider): int
    {
        $frequentHours = (int) config('providers.crawl.new_release_check_hours', 6);
        $slowHours = (int) config('providers.crawl.revisit_after_hours', 72);
        $batch = (int) config('providers.crawl.new_release_batch', 200);

        $reopened = $frontier->reopenStale(
            $provider,
            Carbon::now()->subHours($frequentHours),
            [CrawlType::ArtistLatest],
            $batch,
        );

        $reopened += $frontier->reopenStale(
            $provider,
            Carbon::now()->subHours($slowHours),
            [
                CrawlType::Playlist,
                CrawlType::SearchSongs,
                CrawlType::SearchAlbums,
                CrawlType::SearchArtists,
                CrawlType::SearchPlaylists,
                CrawlType::ArtistSongs,
                CrawlType::ArtistAlbums,
            ],
            $batch,
        );

        return $reopened;
    }
}
