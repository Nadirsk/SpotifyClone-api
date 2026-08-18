<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ProviderUnavailableException;
use App\Models\CrawlTarget;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\CatalogCrawler;
use App\Services\Sync\CrawlFrontier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Drains a batch of the crawl frontier (07_SYNC_ENGINE §5, discovery half).
 *
 * The scheduler fires this on a short cadence and each run claims a bounded
 * number of targets, so throughput scales with how often it is scheduled and
 * how many queue workers are running rather than with any one job running
 * long. That is deliberate: an unbounded "crawl until finished" job would be
 * un-killable, un-observable, and would lose all its progress to a single
 * worker restart.
 *
 * Progress is never held in this job's state. Everything lives in
 * `catalog_crawl_targets`, so a worker dying mid-batch costs one page of one
 * target — the lease expires and another worker picks it up exactly where it
 * stopped.
 */
final class CrawlFrontierJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 07_SYNC_ENGINE §10: at most five attempts, then the dead-letter queue. */
    public int $tries = 5;

    /**
     * How long the uniqueness lock is held before another copy may be queued.
     *
     * `ShouldBeUnique` is load-bearing here, not a nicety. The scheduler
     * dispatches this every five minutes, but one run walks a batch of 25
     * targets and routinely takes ten minutes or more, and `withoutOverlapping()`
     * on the schedule entry does not help: for `Schedule::job()` the *scheduled
     * task* is the dispatch, which finishes in milliseconds, so its mutex is
     * released long before the job it queued has started. Without this the
     * backlog grows by one job every five minutes forever — measured at eleven
     * outstanding copies within half an hour of enabling the scheduler.
     *
     * A second copy would add nothing anyway: it would claim the next batch
     * from the same frontier the running copy is already draining.
     *
     * Matched to `$timeout` so a worker killed mid-run (which on Windows is the
     * normal way a run ends, since pcntl is unavailable and the timeout cannot
     * be enforced) releases the lock rather than blocking the crawl until
     * someone notices.
     */
    public int $uniqueFor = 1_800;

    /**
     * Ceiling on one run, in seconds.
     *
     * A batch of 25 targets where several are 40-page discography walks is a
     * lot of sequential HTTP, and without a timeout a wedged provider
     * connection could hold a worker indefinitely. Set above the worst
     * realistic batch so it only ever fires on a genuine hang.
     */
    public int $timeout = 1_800;

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
        CatalogCrawler $crawler,
        CrawlFrontier $frontier,
        LoggerInterface $logger,
    ): void {
        $adapters = $providers->available();

        if ($this->providerKey !== null) {
            $adapters = array_values(array_filter(
                $adapters,
                fn ($adapter): bool => $adapter->key() === $this->providerKey,
            ));
        }

        if ($adapters === []) {
            $logger->debug('CrawlFrontierJob: no available provider, nothing to crawl');

            return;
        }

        /*
         | Tidy up after any worker that died holding leases. scopeClaimable()
         | already treats an expired lease as available, so this is not what
         | makes the crawl correct — it is what makes the status report honest,
         | by not showing a pile of `running` targets nobody is working on.
         */
        $frontier->reclaimExpiredLeases();

        $batch = $this->batchSize ?? (int) config('providers.crawl.batch_size', 25);

        foreach ($adapters as $adapter) {
            $record = $providers->record($adapter->key());

            if ($record === null) {
                continue;
            }

            $targets = $frontier->claim($adapter->key(), $batch);

            if ($targets->isEmpty()) {
                continue;
            }

            $synced = 0;
            $crawled = 0;

            foreach ($targets as $target) {
                try {
                    $synced += $crawler->crawl($record, $adapter, $target);
                    $crawled++;
                } catch (ProviderUnavailableException $exception) {
                    /*
                     | Stop the whole batch, not just this target. The provider
                     | has parked us, so every remaining target would fail the
                     | same way and burn an attempt doing it. CatalogCrawler has
                     | already released this one back to pending, and the
                     | targets we never claimed were never touched — so the next
                     | scheduled run resumes with nothing lost.
                     */
                    $logger->warning('Crawl stopped: provider unavailable', array_merge(
                        ['crawled_before_stopping' => $crawled],
                        $exception->context(),
                    ));

                    $this->releaseRemaining($frontier, $targets, $target);

                    break;
                }
            }

            $logger->info('Crawl batch finished', [
                'provider' => $adapter->key(),
                'targets_claimed' => $targets->count(),
                'targets_crawled' => $crawled,
                'entities_synced' => $synced,
                'pending_remaining' => $frontier->pendingCount($adapter->key()),
            ]);
        }
    }

    /**
     * Hand back every target after the one that hit the wall.
     *
     * They are still leased as `running`, and leaving them would keep them out
     * of the pool until the lease lapses — up to half an hour of the frontier
     * looking busier and emptier than it is.
     *
     * @param  Collection<int, CrawlTarget>  $targets
     */
    private function releaseRemaining(CrawlFrontier $frontier, $targets, CrawlTarget $stoppedAt): void
    {
        $reached = false;

        foreach ($targets as $target) {
            if ($target->is($stoppedAt)) {
                $reached = true;

                continue;
            }

            if ($reached) {
                $frontier->release($target);
            }
        }
    }
}
