<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The crawl frontier: the work list that drives catalog discovery
 * (07_SYNC_ENGINE §5, extending it from refresh to discovery).
 *
 * The existing sync jobs can only refresh `provider_*_mappings` rows that
 * already exist, and `catalog:bootstrap` only ever finds what its 15 hardcoded
 * search terms happen to match. Neither discovers anything new, which is why a
 * catalog built from them plateaus. This table holds the other half: every
 * entity we know *of* but have not fully crawled, so the crawler always has a
 * next thing to do.
 *
 * Discovery is a closure, not a list. A seed term surfaces artists, albums and
 * playlists; each artist's discography yields more albums and songs; every
 * artist credited on those songs is enqueued in turn. Run until nothing is
 * pending and the reachable catalog has been walked.
 *
 * State lives here rather than in a queue payload for three reasons:
 *
 * - **Resumability.** `cursor_page` is the exact page a target stopped on, so
 *   killing the worker mid-artist costs one page, not 458.
 * - **Deduplication.** The unique key means the same artist discovered from
 *   fifty different songs is one row, not fifty queued jobs.
 * - **Observability.** `catalog:crawl --status` is a GROUP BY over this table;
 *   the same question asked of a queue is unanswerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_crawl_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('provider', 32);

            /*
             | search_term  — a query to exhaust across all four search types
             | artist       — walk the full discography (songs + albums)
             | album        — pull the tracklist
             | playlist     — pull the tracklist, paged
             |
             | A plain string rather than an enum: adding a type later must not
             | require an ALTER on a table with millions of rows.
             */
            $table->string('type', 32);

            /*
             | The provider's own ID, or the literal term for `search_term`.
             | Long enough for a search phrase; provider IDs are far shorter.
             |
             | 191 keeps the composite unique key below inside InnoDB's 3072-byte
             | index limit under utf8mb4 (4 bytes/char), which a 255 here would
             | breach once combined with provider and type.
             */
            $table->string('identifier', 191);

            $table->string('status', 16)->default('pending')
                ->comment('pending | running | completed | failed');

            /*
             | Lower runs first. Seeds and their immediate discoveries are
             | crawled before the long tail, so the catalog is useful early
             | instead of only once the whole closure finishes.
             */
            $table->unsignedSmallInteger('priority')->default(100);

            /*
             | Next page to fetch for paged types. A target that hits
             | `providers.crawl.pages_per_visit` is put back as `pending` with
             | this advanced, so one prolific artist cannot monopolise a worker.
             */
            $table->unsignedInteger('cursor_page')->default(0);

            /*
             | The provider's reported total for this listing, captured on the
             | first page. Purely for progress reporting — the walk itself ends
             | on an empty page, since the totals are not always trustworthy
             | (a playlist detail reports its page size, not its length).
             */
            $table->unsignedInteger('total_expected')->nullable();

            // Entities actually persisted by this target, across every visit.
            $table->unsignedInteger('items_synced')->default(0);

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            /*
             | Lease held by the worker currently crawling this target. A worker
             | that dies leaves the lease to expire rather than the row stuck in
             | `running` forever — see CrawlFrontier::reclaimExpiredLeases().
             */
            $table->timestamp('leased_until')->nullable();

            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            /*
             | One row per entity per provider. This is what makes the closure
             | terminate: re-discovering an artist already in the frontier is an
             | upsert that changes nothing, not another unit of work.
             */
            $table->unique(['provider', 'type', 'identifier']);

            // The claim query: pending/expired-lease rows, best priority first,
            // oldest first within a priority.
            $table->index(['status', 'priority', 'leased_until']);

            // Finds completed targets that are due a revisit for new releases.
            $table->index(['status', 'completed_at']);

            // Powers --status without scanning the table.
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_crawl_targets');
    }
};
