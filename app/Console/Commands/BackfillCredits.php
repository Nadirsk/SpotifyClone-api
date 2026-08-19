<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\Providers\ProviderSongData;
use App\Enums\CreditRole;
use App\Exceptions\ProviderUnavailableException;
use App\Models\Provider;
use App\Models\ProviderSongMapping;
use App\Models\Song;
use App\Observers\SongObserver;
use App\Services\Providers\JioSaavn\JioSaavnAdapter;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\CreditWriter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Populates `song_credits` for songs that were synced before it existed.
 *
 * ## Why this is not a re-crawl
 *
 * Adding the credits table only fixes songs synced *after* it. Everything
 * already in the catalog was written by an adapter that parsed the credit list
 * off every payload and then discarded all but the IDs, and the schema keeps no
 * raw payload to recover them from — the `add_provider_detail_columns` migration
 * rejected a JSON blob on purpose. So the payloads have to be read again.
 *
 * Read again is not the same as *searched* again, and that distinction is what
 * makes this cheap. `provider_song_mappings` already holds the provider's own ID
 * for every song in the catalog, and the wrapper's `/songs?ids=` takes fifty of
 * them per call. 36,000 songs is therefore ~730 requests against a local
 * wrapper — minutes, not the multi-day search-and-rediscover crawl that would be
 * needed if those IDs had not been kept.
 *
 * ## Re-runnable
 *
 * By default this skips songs that already have credits, so an interrupted pass
 * resumes rather than starting over and a nightly run costs only the songs added
 * since the last one. `--refresh` reprocesses everything, for when the parser
 * itself has changed.
 *
 * The mapping checksum is rewritten as each song lands. Credits are folded into
 * {@see ProviderSongData::checksum()}, so without this every
 * song would look changed to the next incremental sync and the entire catalog
 * would be rewritten once for nothing.
 */
final class BackfillCredits extends Command
{
    protected $signature = 'catalog:backfill-credits
        {--limit=0 : Max songs to process, 0 = no limit}
        {--refresh : Reprocess songs that already have credits}
        {--chunk=50 : Provider IDs per request; the wrapper caps this at 50}';

    protected $description = 'Fetch provider credit lists for already-synced songs and populate song_credits';

    public function __construct(
        private readonly ProviderManager $providers,
        private readonly CreditWriter $credits,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $adapter = $this->providers->get('jiosaavn');
        $record = $this->providers->record('jiosaavn');

        /*
         | Narrowed to the one adapter that parses credits rather than looping
         | every enabled provider. The others return an empty credit list, and
         | CreditWriter treats empty as "said nothing" — so including them would
         | spend a request per song to learn nothing. When a second adapter
         | learns to parse credits this becomes a loop; until then a loop would
         | only obscure that it is really one provider.
         */
        if (! $adapter instanceof JioSaavnAdapter || $record === null) {
            $this->error('The JioSaavn provider is not enabled — it is the only adapter that parses credits.');

            return self::FAILURE;
        }

        $healed = $this->reassertDisplayArtistCredits();

        if ($healed > 0) {
            $this->warn("Restored {$healed} missing display-artist credit(s) before starting.");
        }

        $chunk = max(1, min(50, (int) $this->option('chunk')));
        $limit = max(0, (int) $this->option('limit'));
        $available = $this->targets($record)->count();
        $total = $limit > 0 ? min($limit, $available) : $available;

        if ($total === 0) {
            $this->info('Nothing to backfill — every song already has credits.');

            return self::SUCCESS;
        }

        $this->info("Backfilling credits for {$total} song(s), {$chunk} provider IDs per request...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $written = 0;
        $creditRows = 0;
        $unanswered = 0;

        /*
         | Chunked at the query level, not by loading every mapping and slicing
         | in PHP. `with('song')` on the whole catalog at once builds a single
         | `where id in (...)` holding 36,000 UUIDs — about 1.4 MB of SQL, which
         | MySQL refuses outright with `max_allowed_packet` (1153). Fetching
         | fifty rows at a time keeps that statement small and means the command
         | never holds the catalog in memory either.
         |
         | `chunkById` rather than `chunk`: the default filter excludes songs
         | that already have credits, and this loop is what gives them credits.
         | Offset paging would therefore skip a page's worth of songs every time
         | the result set shrank underneath it. Keying on the last id read is
         | immune to that.
         */
        $processed = 0;

        $this->targets($record)->chunkById($chunk, function (Collection $batch) use (
            $adapter, $record, $bar, $limit, $total, &$processed, &$written, &$creditRows, &$unanswered
        ): ?bool {
            /*
             | `--limit` is enforced here rather than as `limit()` on the query,
             | because chunkById replaces the builder's own limit with its page
             | size — a limit set there is silently ignored and the command runs
             | over the whole catalog. Trimming the batch keeps the last page
             | from overshooting the number that was asked for.
             */
            if ($limit > 0) {
                $batch = $batch->take($limit - $processed);

                if ($batch->isEmpty()) {
                    return false;
                }
            }

            $processed += $batch->count();

            /*
             | Keyed by provider ID so a response can be matched back to its
             | local song without a second query. The provider returns a batch
             | in its own order and silently omits IDs it no longer recognises,
             | so position cannot be relied on.
             */
            $byExternalId = $batch->keyBy('provider_song_id');

            try {
                $records = $adapter->songsByIds(array_map('strval', $byExternalId->keys()->all()));
            } catch (ProviderUnavailableException $e) {
                $this->newLine(2);
                $this->warn("Paused: {$e->getMessage()} Rerun to continue — finished songs are skipped.");

                // false stops chunkById; the summary is printed once, below.
                return false;
            }

            $answered = 0;

            foreach ($records as $data) {
                $mapping = $byExternalId->get($data->externalId);

                if ($mapping === null || $mapping->song === null) {
                    continue;
                }

                $answered++;

                $rows = DB::transaction(function () use ($mapping, $record, $data): int {
                    $rows = $this->credits->write($mapping->song, $record, $data->credits);

                    /*
                     | Keep the checksum in step with what was just written, so
                     | the next incremental sync short-circuits on this song
                     | instead of rewriting it purely because credits joined the
                     | hash. Only when credits actually landed — a payload that
                     | carried none has not brought the stored row up to date.
                     */
                    if ($rows > 0) {
                        $mapping->forceFill([
                            'checksum' => $data->checksum(),
                            'last_synced_at' => now(),
                        ])->save();
                    }

                    return $rows;
                });

                if ($rows > 0) {
                    $written++;
                    $creditRows += $rows;
                }
            }

            $unanswered += $batch->count() - $answered;
            $bar->advance($batch->count());

            return $limit > 0 && $processed >= $total ? false : null;
        });

        $bar->finish();
        $this->newLine(2);
        $this->summary($written, $creditRows, $unanswered);

        return self::SUCCESS;
    }

    /**
     * Re-assert that every song has a `primary` credit for its display artist.
     *
     * That invariant is what lets {@see Song::scopeCreditedTo()} be a
     * single indexed lookup, and a song missing its row does not fail loudly — it
     * quietly vanishes from its own artist's page. Both writers maintain it
     * ({@see SongObserver}, {@see CreditWriter}) and a migration
     * seeded the catalog, so this should always find nothing.
     *
     * It exists because it once found nine. A full backfill was in flight while
     * the seeding migration ran, using a build of CreditWriter that replaced a
     * song's credit list without re-seeding the display artist; the two
     * interleaved and nine songs ended up with neither. That exact race cannot
     * recur — the writer now seeds unconditionally — but "the invariant is
     * maintained by three places and verified by none" is the shape of the
     * problem, not the specific race. So the documented repair command checks it,
     * cheaply, every run.
     *
     * `insertOrIgnore` in one statement per batch, keyset-paged the same way the
     * migration is, for the same `max_allowed_packet` reason.
     */
    private function reassertDisplayArtistCredits(): int
    {
        $now = now();
        $after = '';
        $restored = 0;

        while (true) {
            $songs = DB::table('songs')
                ->select('id', 'artist_id')
                ->whereNull('deleted_at')
                ->where('id', '>', $after)
                ->whereNotExists(function ($credit): void {
                    $credit
                        ->from('song_credits')
                        ->whereColumn('song_credits.song_id', 'songs.id')
                        ->whereColumn('song_credits.artist_id', 'songs.artist_id')
                        ->where('song_credits.role', CreditRole::Primary->value)
                        ->selectRaw('1');
                })
                ->orderBy('id')
                ->limit(1000)
                ->get();

            if ($songs->isEmpty()) {
                break;
            }

            $after = (string) $songs->last()->id;
            $rows = [];

            foreach ($songs as $song) {
                if (! is_string($song->artist_id) || $song->artist_id === '') {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'song_id' => $song->id,
                    'artist_id' => $song->artist_id,
                    'role' => CreditRole::Primary->value,
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                $restored += DB::table('song_credits')->insertOrIgnore($rows);
            }
        }

        return $restored;
    }

    /**
     * The songs to fetch, as a query over their provider mappings.
     *
     * A builder rather than a result set, so the caller can walk it in chunks —
     * see the note there on `max_allowed_packet`.
     *
     * Eager-loads the song because `Model::preventLazyLoading()` is on outside
     * production and every mapping's song is read.
     *
     * @return Builder<ProviderSongMapping>
     */
    private function targets(Provider $record): Builder
    {
        $query = ProviderSongMapping::query()
            ->with('song')
            ->where('provider_id', $record->getKey())
            /*
             | A mapping whose song has been soft-deleted has nothing to write
             | credits onto, and `with('song')` hands back null for it.
             */
            ->whereHas('song');

        if (! $this->option('refresh')) {
            /*
             | "Has no credits yet" cannot be `whereDoesntHave('song.credits')`,
             | because every song has one: the display-artist `primary` row that
             | Song::scopeCreditedTo() depends on, written by SongObserver and
             | seeded across the catalog by migration 2026_08_19_140100. That row
             | says nothing about whether the provider has been asked.
             |
             | Learned by running it: the seed migration was applied while a full
             | backfill was in flight, every remaining song instantly matched
             | "already has credits", and the walk stopped after 5,498 of 36,151
             | reporting success.
             |
             | So the predicate is "has no credit that ISN'T the display artist's
             | primary row" — anything else can only have come from a provider.
             */
            $query->whereExists(function ($song): void {
                $song
                    ->from('songs')
                    ->whereColumn('songs.id', 'provider_song_mappings.song_id')
                    ->whereNull('songs.deleted_at')
                    ->whereNotExists(function ($credit): void {
                        $credit
                            ->from('song_credits')
                            ->whereColumn('song_credits.song_id', 'songs.id')
                            ->where(function ($other): void {
                                $other
                                    ->whereColumn('song_credits.artist_id', '!=', 'songs.artist_id')
                                    ->orWhere('song_credits.role', '!=', CreditRole::Primary->value);
                            })
                            ->selectRaw('1');
                    })
                    ->selectRaw('1');
            });
        }

        /*
         | No limit() here on purpose — see the note in handle(). chunkById
         | overwrites it, so `--limit` has to be enforced batch by batch.
         */
        return $query;
    }

    private function summary(int $written, int $creditRows, int $unanswered): void
    {
        $this->info("Wrote credits for {$written} song(s) — {$creditRows} credit row(s).");

        if ($unanswered > 0) {
            /*
             | Reported rather than swallowed. An ID the provider no longer
             | recognises is a real state — a track pulled from its catalogue
             | since it was synced — and a silent zero would read as success.
             */
            $this->warn("{$unanswered} song(s) were not returned by the provider and still have no credits.");
        }
    }
}
