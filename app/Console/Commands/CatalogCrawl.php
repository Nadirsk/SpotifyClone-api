<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CrawlStatus;
use App\Enums\CrawlType;
use App\Exceptions\ProviderUnavailableException;
use App\Models\CrawlTarget;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\CatalogCrawler;
use App\Services\Sync\CrawlFrontier;
use Illuminate\Console\Command;

/**
 * Operator front-end to the crawl frontier.
 *
 * The scheduler already drains the frontier continuously in production, so
 * this exists for the two things a schedule cannot do: put the first seeds in
 * (a frontier with nothing in it never starts), and watch what is happening
 * while it runs.
 *
 *   php artisan catalog:crawl --seed          # queue the seed terms
 *   php artisan catalog:crawl --status        # what is pending / done
 *   php artisan catalog:crawl --drain         # crawl in the foreground until empty
 *   php artisan catalog:crawl --drain --max=5 # ...for at most 5 batches
 *
 * `--drain` runs the same {@see CatalogCrawler} the queue does, in-process. It
 * is for watching a crawl work, not for production throughput — the scheduled
 * job is the real path.
 */
final class CatalogCrawl extends Command
{
    protected $signature = 'catalog:crawl
        {--seed : Queue the seed search terms}
        {--status : Show frontier counts and exit}
        {--drain : Crawl in the foreground until the frontier is empty}
        {--max=0 : With --drain, stop after this many batches (0 = unlimited)}
        {--batch= : Targets per batch (defaults to providers.crawl.batch_size)}
        {--provider= : Restrict to one provider key}
        {--reset-failed : Return failed targets to the queue and exit}';

    protected $description = 'Seed, inspect and drain the catalog discovery crawl';

    /**
     * Seed terms for a cold frontier.
     *
     * Reuses the curated list from `catalog:bootstrap` rather than inventing a
     * second one: the terms are chosen for this catalog's Indian/regional
     * focus, and having two lists drift apart would mean two different answers
     * to "what does a fresh install contain".
     *
     * The list matters far less here than it does for the bootstrap, though.
     * Bootstrap's catalog *is* whatever these terms match; the crawl's is the
     * transitive closure reachable from them, so these only need to be a
     * varied enough entry point to reach the graph, not a representative
     * sample of it.
     *
     * @var list<string>
     */
    private const SEED_TERMS = BootstrapCatalog::TERMS;

    public function __construct(
        private readonly ProviderManager $providers,
        private readonly CrawlFrontier $frontier,
        private readonly CatalogCrawler $crawler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $adapters = $this->providers->enabled();

        if ($this->option('provider') !== null) {
            $adapters = array_values(array_filter(
                $adapters,
                fn ($adapter): bool => $adapter->key() === $this->option('provider'),
            ));
        }

        if ($adapters === []) {
            $this->error('No provider is enabled and configured — nothing to crawl.');

            return self::FAILURE;
        }

        if ($this->option('reset-failed')) {
            return $this->resetFailed($adapters);
        }

        if ($this->option('seed')) {
            $this->seed($adapters);
        }

        if ($this->option('drain')) {
            $this->drain($adapters);
        }

        // --status is also the default when nothing else was asked for, so a
        // bare `catalog:crawl` reports rather than silently doing nothing.
        if ($this->option('status') || ! ($this->option('seed') || $this->option('drain'))) {
            $this->status($adapters);
        }

        return self::SUCCESS;
    }

    /** @param list<\App\Contracts\Providers\ProviderAdapter> $adapters */
    private function seed(array $adapters): void
    {
        foreach ($adapters as $adapter) {
            $created = $this->frontier->enqueueMany(
                $adapter->key(),
                CrawlType::SearchTerm,
                self::SEED_TERMS,
            );

            $this->info(sprintf(
                'Seeded %s: %d new term(s), %d already known.',
                $adapter->key(),
                $created,
                count(self::SEED_TERMS) - $created,
            ));
        }
    }

    /** @param list<\App\Contracts\Providers\ProviderAdapter> $adapters */
    private function drain(array $adapters): void
    {
        $batchSize = (int) ($this->option('batch') ?: config('providers.crawl.batch_size', 25));
        $maxBatches = (int) $this->option('max');

        foreach ($adapters as $adapter) {
            $record = $this->providers->record($adapter->key());

            if ($record === null) {
                $this->warn("No providers-table row for '{$adapter->key()}'; skipping.");

                continue;
            }

            $this->info("--- draining {$adapter->key()} ---");

            $batches = 0;
            $totalSynced = 0;

            while ($maxBatches === 0 || $batches < $maxBatches) {
                $this->frontier->reclaimExpiredLeases();

                $targets = $this->frontier->claim($adapter->key(), $batchSize);

                if ($targets->isEmpty()) {
                    $this->info('  frontier empty — nothing left to crawl.');

                    break;
                }

                $batches++;
                $stopped = false;

                foreach ($targets as $target) {
                    try {
                        $synced = $this->crawler->crawl($record, $adapter, $target);
                    } catch (ProviderUnavailableException $exception) {
                        $this->warn("  paused: {$exception->reason}. Rerun to continue.");
                        $stopped = true;

                        break;
                    }

                    $totalSynced += $synced;

                    $this->line(sprintf(
                        '  %-16s %-28s +%d',
                        $target->type->value,
                        mb_strimwidth($target->identifier, 0, 28, '…'),
                        $synced,
                    ));
                }

                if ($stopped) {
                    break;
                }

                $this->line(sprintf(
                    '  [batch %d] %d entities so far, %d pending',
                    $batches,
                    $totalSynced,
                    $this->frontier->pendingCount($adapter->key()),
                ));
            }

            $this->info(sprintf('  done: %d batches, %d entities synced.', $batches, $totalSynced));
        }
    }

    /** @param list<\App\Contracts\Providers\ProviderAdapter> $adapters */
    private function status(array $adapters): void
    {
        foreach ($adapters as $adapter) {
            $summary = $this->frontier->summary($adapter->key());

            $this->newLine();
            $this->info("--- {$adapter->key()} frontier ---");

            if ($summary === []) {
                $this->line('  empty — run with --seed to start.');

                continue;
            }

            $rows = [];

            foreach ($summary as $type => $counts) {
                $rows[] = [
                    $type,
                    $counts[CrawlStatus::Pending->value] ?? 0,
                    $counts[CrawlStatus::Running->value] ?? 0,
                    $counts[CrawlStatus::Completed->value] ?? 0,
                    $counts[CrawlStatus::Failed->value] ?? 0,
                    $counts['items'] ?? 0,
                ];
            }

            $this->table(['type', 'pending', 'running', 'done', 'failed', 'entities'], $rows);
        }

        $this->newLine();
        $this->line('Catalog: '.implode('  ', [
            'songs='.\App\Models\Song::query()->count(),
            'albums='.\App\Models\Album::query()->count(),
            'artists='.\App\Models\Artist::query()->count(),
            'playlists='.\App\Models\Playlist::query()->providerCurated()->count(),
        ]));
    }

    /** @param list<\App\Contracts\Providers\ProviderAdapter> $adapters */
    private function resetFailed(array $adapters): int
    {
        foreach ($adapters as $adapter) {
            $reset = CrawlTarget::query()
                ->where('provider', $adapter->key())
                ->where('status', CrawlStatus::Failed)
                ->update([
                    'status' => CrawlStatus::Pending->value,
                    'attempts' => 0,
                    'last_error' => null,
                ]);

            $this->info("Reset {$reset} failed target(s) for {$adapter->key()}.");
        }

        return self::SUCCESS;
    }
}
