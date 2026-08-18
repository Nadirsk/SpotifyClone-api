<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\CrawlStatus;
use App\Enums\CrawlType;
use App\Models\CrawlTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Read/write access to the crawl frontier — the queue of things still to
 * discover. See the `catalog_crawl_targets` migration for why the frontier is
 * a table rather than a job queue.
 *
 * This class owns the concurrency rules and nothing else; {@see CatalogCrawler}
 * owns what a target actually *means*. Splitting them keeps the locking small
 * enough to reason about: everything here is either an idempotent upsert or a
 * single lock-guarded claim.
 */
final class CrawlFrontier
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Add a target, or leave the existing one alone.
     *
     * Idempotent by design and called constantly — every song synced offers up
     * its artist, every album its tracks — so the overwhelmingly common case
     * is "already known", which must cost one cheap indexed lookup and no
     * write. Re-enqueueing must never reset a completed target's state or the
     * crawl would loop forever on the same entities.
     *
     * @param  string|int  $identifier  Accepts an int because provider IDs are not consistently
     *                                  typed in JSON: JioSaavn quotes an artist ID in one payload
     *                                  ("459320") and emits it bare in another, and a playlist ID
     *                                  can be as short as `49`, which json_decode hands back as an
     *                                  int. Under strict_types a string-only parameter turned every
     *                                  one of those into a TypeError that failed the whole target —
     *                                  silently, since the crawler catches and retries. Normalizing
     *                                  at this single choke point is what keeps every caller from
     *                                  having to know which fields the provider quotes.
     * @param  int|null  $priority  Defaults to the type's own. Pass a lower number to jump the queue.
     * @return bool Whether a new target was created.
     */
    public function enqueue(string $provider, CrawlType $type, string|int $identifier, ?int $priority = null): bool
    {
        $identifier = trim((string) $identifier);

        if ($identifier === '') {
            return false;
        }

        /*
         | The unique key is on (provider, type, identifier) and `identifier`
         | is a 191-char column, so anything longer would be truncated by MySQL
         | and could collide with a different target. Only search terms ever
         | come close; provider IDs are a dozen characters.
         */
        if (mb_strlen($identifier) > 191) {
            $identifier = mb_substr($identifier, 0, 191);
        }

        if ($this->atCapacity()) {
            return false;
        }

        /*
         | insertOrIgnore rather than updateOrCreate: this is the hot path, and
         | a read-then-write would both cost an extra round trip and race
         | against parallel workers discovering the same artist. The unique
         | index arbitrates instead, and the ignored insert is the "already
         | known" answer.
         */
        $created = CrawlTarget::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'provider' => $provider,
            'type' => $type->value,
            'identifier' => $identifier,
            'status' => CrawlStatus::Pending->value,
            'priority' => $priority ?? $type->defaultPriority(),
            'cursor_page' => 0,
            'items_synced' => 0,
            'attempts' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $created > 0;
    }

    /**
     * Enqueue many identifiers of one type at once.
     *
     * @param  iterable<string|int>  $identifiers
     * @return int How many were newly created.
     */
    public function enqueueMany(string $provider, CrawlType $type, iterable $identifiers, ?int $priority = null): int
    {
        $created = 0;

        foreach ($identifiers as $identifier) {
            if ($this->enqueue($provider, $type, $identifier, $priority)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Claim up to $limit targets for this worker, marking them `running` with
     * a lease.
     *
     * The SELECT and the UPDATE run inside one transaction with
     * `lockForUpdate()`, which is what stops two workers claiming the same
     * target. Without the row lock, both would see the same pending rows,
     * both would mark them running, and both would crawl the same pages —
     * doubling provider traffic and racing each other's writes.
     *
     * @return Collection<int, CrawlTarget>
     */
    public function claim(string $provider, int $limit): Collection
    {
        $leaseSeconds = (int) config('providers.crawl.lease_seconds', 1800);

        return DB::transaction(function () use ($provider, $limit, $leaseSeconds): Collection {
            /** @var Collection<int, CrawlTarget> $targets */
            $targets = CrawlTarget::query()
                ->where('provider', $provider)
                ->claimable()
                ->orderBy('priority')
                ->orderBy('created_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($targets->isEmpty()) {
                return $targets;
            }

            CrawlTarget::query()
                ->whereIn('id', $targets->modelKeys())
                ->update([
                    'status' => CrawlStatus::Running->value,
                    'leased_until' => Carbon::now()->addSeconds($leaseSeconds),
                    'updated_at' => Carbon::now(),
                ]);

            return $targets;
        });
    }

    /**
     * Mark a target fully walked.
     *
     * `completed_at` is what the revisit sweep sorts on, and the target is
     * kept rather than deleted so re-discovering it stays a no-op instead of
     * re-queueing work already done.
     */
    public function complete(CrawlTarget $target, int $itemsSynced): void
    {
        $target->forceFill([
            'status' => CrawlStatus::Completed->value,
            'items_synced' => $target->items_synced + $itemsSynced,
            'cursor_page' => 0,
            'attempts' => 0,
            'last_error' => null,
            'leased_until' => null,
            'last_crawled_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Put a target back for another visit, resuming at $nextPage.
     *
     * Used when a target hit `pages_per_visit` with pages still to go. It goes
     * back as `pending` with no lease, so any worker can pick it up — the work
     * is positional, not owned.
     */
    public function reschedule(CrawlTarget $target, int $nextPage, int $itemsSynced, ?int $totalExpected = null): void
    {
        $target->forceFill([
            'status' => CrawlStatus::Pending->value,
            'cursor_page' => $nextPage,
            'items_synced' => $target->items_synced + $itemsSynced,
            'total_expected' => $totalExpected ?? $target->total_expected,
            'attempts' => 0,
            'last_error' => null,
            'leased_until' => null,
            'last_crawled_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Record a failure, retrying until `max_attempts` and then parking the
     * target as `failed`.
     *
     * A retried target keeps its `cursor_page`, so a transient provider blip
     * costs the current page rather than the whole discography walked so far.
     */
    public function fail(CrawlTarget $target, string $reason): void
    {
        $attempts = $target->attempts + 1;
        $exhausted = $attempts >= (int) config('providers.crawl.max_attempts', 5);

        $target->forceFill([
            'status' => ($exhausted ? CrawlStatus::Failed : CrawlStatus::Pending)->value,
            'attempts' => $attempts,
            // Column is TEXT; provider errors are occasionally enormous.
            'last_error' => mb_substr($reason, 0, 2000),
            'leased_until' => null,
            'last_crawled_at' => Carbon::now(),
        ])->save();

        if ($exhausted) {
            $this->logger->warning('Crawl target parked after repeated failures', [
                'type' => $target->type->value,
                'identifier' => $target->identifier,
                'attempts' => $attempts,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Release a target without counting a failure against it.
     *
     * Used when the *provider* stopped answering rather than the target being
     * bad. Burning an attempt there would eventually park perfectly good
     * targets as `failed` just because the wrapper was down for an hour.
     */
    public function release(CrawlTarget $target): void
    {
        $target->forceFill([
            'status' => CrawlStatus::Pending->value,
            'leased_until' => null,
        ])->save();
    }

    /**
     * Return targets whose lease lapsed to the pending pool.
     *
     * Belt-and-braces: `scopeClaimable()` already treats an expired lease as
     * claimable, so nothing is stuck without this. It exists so the status
     * report and the pending counts tell the truth after a crash, rather than
     * showing a pile of `running` targets no worker is holding.
     *
     * @return int Rows reclaimed.
     */
    public function reclaimExpiredLeases(): int
    {
        return CrawlTarget::query()
            ->where('status', CrawlStatus::Running)
            ->where('leased_until', '<', Carbon::now())
            ->update([
                'status' => CrawlStatus::Pending->value,
                'leased_until' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Re-open completed targets that are old enough to be worth another walk,
     * so newly added content is picked up.
     *
     * This is the automatic half of "new songs are fetched as they appear":
     * an artist whose discography was complete a week ago is walked again, and
     * anything released since lands in the catalog without anyone asking.
     *
     * @param  list<CrawlType>  $types  Restrict to these types; empty means all.
     * @return int Rows re-opened.
     */
    public function reopenStale(string $provider, Carbon $before, array $types = [], ?int $limit = null): int
    {
        $query = CrawlTarget::query()
            ->where('provider', $provider)
            ->dueForRevisit($before);

        if ($types !== []) {
            $query->whereIn('type', array_map(static fn (CrawlType $type): string => $type->value, $types));
        }

        /*
         | MySQL cannot ORDER BY / LIMIT an UPDATE that also has a subquery on
         | the same table, so the ids are selected first and updated by key.
         */
        if ($limit !== null) {
            $ids = (clone $query)->orderBy('completed_at')->limit($limit)->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            $query = CrawlTarget::query()->whereIn('id', $ids);
        }

        return $query->update([
            'status' => CrawlStatus::Pending->value,
            'cursor_page' => 0,
            'leased_until' => null,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Counts per type and status, for `catalog:crawl --status`.
     *
     * @return array<string, array<string, int>>
     */
    public function summary(string $provider): array
    {
        /*
         | Deliberately the query builder rather than Eloquent. Going through
         | the model would apply CrawlTarget's enum casts to the grouped `type`
         | and `status` columns, handing back CrawlType/CrawlStatus objects —
         | which cannot be used as the array keys this builds, and which are
         | not what the caller wants to print anyway.
         */
        $rows = DB::table('catalog_crawl_targets')
            ->where('provider', $provider)
            ->selectRaw('type, status, COUNT(*) as total, SUM(items_synced) as items')
            ->groupBy('type', 'status')
            ->get();

        $summary = [];

        foreach ($rows as $row) {
            /** @var object{type: string, status: string, total: int, items: int|string|null} $row */
            $summary[$row->type][$row->status] = (int) $row->total;
            $summary[$row->type]['items'] = ($summary[$row->type]['items'] ?? 0) + (int) $row->items;
        }

        return $summary;
    }

    /** How many targets are waiting to be crawled. */
    public function pendingCount(string $provider): int
    {
        return CrawlTarget::query()
            ->where('provider', $provider)
            ->claimable()
            ->count();
    }

    /**
     * Whether the frontier has grown past `providers.crawl.max_pending` and
     * should stop accepting new targets.
     *
     * Enqueueing stops; crawling does not. The frontier drains, the closure
     * keeps producing candidates that are dropped, and the crawl converges on
     * what it already knows about rather than the disk filling unattended.
     *
     * Cached for a few seconds because `enqueue()` is called thousands of
     * times per target and a COUNT over a multi-million-row table on each one
     * would cost more than the crawl itself.
     */
    private function atCapacity(): bool
    {
        $max = config('providers.crawl.max_pending');

        if ($max === null) {
            return false;
        }

        $count = cache()->remember(
            'crawl:pending-total',
            now()->addSeconds(30),
            static fn (): int => CrawlTarget::query()->where('status', CrawlStatus::Pending)->count(),
        );

        if ($count < (int) $max) {
            return false;
        }

        $this->logger->warning('Crawl frontier at capacity; not enqueueing further targets', [
            'pending' => $count,
            'max_pending' => (int) $max,
        ]);

        return true;
    }
}
