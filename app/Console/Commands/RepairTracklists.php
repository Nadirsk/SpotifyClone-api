<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\Providers\ProviderSongData;
use App\Exceptions\ProviderUnavailableException;
use App\Models\Album;
use App\Models\Provider;
use App\Models\ProviderAlbumMapping;
use App\Models\ProviderSongMapping;
use App\Services\Providers\JioSaavn\JioSaavnAdapter;
use App\Services\Providers\ProviderManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-reads album tracklists from the provider and rewrites `songs.track_number`.
 *
 * Two distinct defects, one cure.
 *
 * **Duplicate positions.** `SyncService` used to move a song between albums
 * whenever a payload named a different release, and it carried the old
 * `track_number` across with it — so several tracks ended up claiming position 1
 * on the same album. That behaviour is fixed (see
 * `SyncService::withStableAlbumMembership()`), which stops new collisions but
 * cannot untangle the ones already written: the correct order is not derivable
 * from anything in the database, only from the provider's own tracklist.
 *
 * **Missing positions.** Far larger, and it is not a bug so much as a
 * consequence of how the catalog was discovered. A song search returns songs
 * with no album context, so `trackNumber` is null on every search-sourced record
 * — as {@see ProviderSongData::$trackNumber} says, only a
 * tracklist fetch can know a position. Most of this catalog arrived by search,
 * which is why most album rows have no running order and album pages fall back
 * to insertion order.
 *
 * Both are repaired by asking the one endpoint that knows. `/albums?id=` returns
 * the tracks in the album's real order, which {@see JioSaavnAdapter::albumTracks()}
 * numbers by position.
 *
 * ## Matching a returned track to a local song
 *
 * Through `provider_song_mappings` on the provider's own song ID, never by
 * title. Titles are exactly what cannot be trusted here: the same recording
 * appears under several names across compilations, which is how songs ended up
 * on the wrong album in the first place. A track the provider returns that maps
 * to no local song is skipped rather than created — this command repairs
 * ordering, and pulling in new songs is `catalog:crawl`'s job.
 *
 * A song is only renumbered when it is already a member of the album being
 * repaired. The tracklist is authoritative about *order*, and this deliberately
 * does not let it also reassign membership: that is the write that caused the
 * original damage, and it now belongs solely to the guarded path in SyncService.
 */
final class RepairTracklists extends Command
{
    protected $signature = 'catalog:repair-tracklists
        {--scope=duplicates : Which albums to repair — duplicates, missing, or all}
        {--limit=0 : Max albums to process, 0 = no limit}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Re-fetch album tracklists to repair duplicate or missing track_number values';

    public function __construct(private readonly ProviderManager $providers)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $adapter = $this->providers->get('jiosaavn');
        $record = $this->providers->record('jiosaavn');

        if (! $adapter instanceof JioSaavnAdapter || $record === null) {
            $this->error('The JioSaavn provider is not enabled — it is the only adapter that returns tracklists.');

            return self::FAILURE;
        }

        $scope = (string) $this->option('scope');

        if (! in_array($scope, ['duplicates', 'missing', 'all'], true)) {
            $this->error("Unknown --scope={$scope}. Use duplicates, missing, or all.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $available = $this->targets($record, $scope)->count();
        $total = $limit > 0 ? min($limit, $available) : $available;

        if ($total === 0) {
            $this->info("Nothing to repair in scope '{$scope}'.");

            return self::SUCCESS;
        }

        $this->info(
            ($dryRun ? 'DRY RUN — ' : '')
            ."Repairing tracklists for {$total} album(s) in scope '{$scope}'..."
        );

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $repaired = 0;
        $renumbered = 0;
        $unmatched = 0;
        $paused = false;

        /*
         | One request per album, so this walks albums rather than batching IDs
         | the way the credits backfill does — `/albums` takes a single id. That
         | makes the `missing` scope genuinely long-running (tens of thousands of
         | albums at roughly a quarter-second each), which is why --limit and the
         | resume behaviour below matter more here than there.
         |
         | chunkById for the same reason as the credits backfill: repairing an
         | album removes it from the `duplicates` and `missing` result sets, so
         | offset paging would skip a page each time the set shrank.
         */
        $this->targets($record, $scope)->chunkById(100, function (Collection $batch) use (
            $adapter, $record, $bar, $dryRun, $limit, $total,
            &$processed, &$repaired, &$renumbered, &$unmatched, &$paused
        ): ?bool {
            if ($limit > 0) {
                $batch = $batch->take($limit - $processed);

                if ($batch->isEmpty()) {
                    return false;
                }
            }

            foreach ($batch as $mapping) {
                if ($mapping->album === null) {
                    continue;
                }

                $processed++;

                try {
                    $tracks = $adapter->albumTracks((string) $mapping->provider_album_id);
                } catch (ProviderUnavailableException $e) {
                    $this->newLine(2);
                    $this->warn("Paused: {$e->getMessage()} Rerun to continue where this left off.");
                    $paused = true;

                    return false;
                }

                $changes = $this->positionsFor($mapping->album, $record, $tracks);

                $unmatched += $changes['unmatched'];

                if ($changes['updates'] !== []) {
                    if (! $dryRun) {
                        $this->apply($changes['updates']);
                    }

                    $repaired++;
                    $renumbered += count($changes['updates']);
                }

                $bar->advance();
            }

            return $limit > 0 && $processed >= $total ? false : null;
        });

        $bar->finish();
        $this->newLine(2);

        $verb = $dryRun ? 'would renumber' : 'renumbered';
        $this->info("Read {$processed} album(s); {$verb} {$renumbered} track(s) across {$repaired} album(s).");

        if ($unmatched > 0) {
            $this->line(
                "  {$unmatched} returned track(s) matched no local song on the album and were left alone "
                .'(not yet crawled, or on a different release locally).'
            );
        }

        if ($paused) {
            return self::SUCCESS;
        }

        if (! $dryRun) {
            $this->reportRemainingDuplicates();
        }

        return self::SUCCESS;
    }

    /**
     * Work out the new position for each song on this album.
     *
     * @param  list<ProviderSongData>  $tracks
     * @return array{updates: array<string, int>, unmatched: int}
     */
    private function positionsFor(Album $album, Provider $record, array $tracks): array
    {
        if ($tracks === []) {
            return ['updates' => [], 'unmatched' => 0];
        }

        $externalIds = array_map(static fn ($track): string => $track->externalId, $tracks);

        /*
         | provider song id => local song id, for songs already on this album.
         | Constraining to the album is what keeps this from reassigning
         | membership: a returned track that lives on a different album locally
         | is counted as unmatched and left exactly where it is.
         */
        $localIds = ProviderSongMapping::query()
            ->where('provider_id', $record->getKey())
            ->whereIn('provider_song_id', $externalIds)
            ->whereHas('song', static fn (Builder $song): Builder => $song->where('album_id', $album->getKey()))
            ->pluck('song_id', 'provider_song_id');

        $current = DB::table('songs')
            ->where('album_id', $album->getKey())
            ->whereNull('deleted_at')
            ->pluck('track_number', 'id');

        $updates = [];
        $unmatched = 0;

        foreach ($tracks as $track) {
            $songId = $localIds->get($track->externalId);

            if ($songId === null) {
                $unmatched++;

                continue;
            }

            if ($track->trackNumber === null) {
                continue;
            }

            // Only write a real change, so a re-run over a healthy album is
            // read-only and the reported counts mean something.
            if ((int) ($current[$songId] ?? 0) !== $track->trackNumber) {
                $updates[(string) $songId] = $track->trackNumber;
            }
        }

        return ['updates' => $updates, 'unmatched' => $unmatched];
    }

    /**
     * @param  array<string, int>  $updates  Local song id => new position.
     */
    private function apply(array $updates): void
    {
        DB::transaction(function () use ($updates): void {
            /*
             | Cleared first, then set. An album whose positions are being
             | permuted — track 3 becoming track 1 while track 1 becomes 2 —
             | passes through states where two songs hold the same number, and
             | while nothing in the schema forbids that today, writing through
             | it would be the same transient the repair exists to remove.
             |
             | Raw update() rather than saving models: no observer, no touched
             | timestamp, and one statement per album instead of one per track.
             | This changes a position, not the record's content.
             */
            DB::table('songs')->whereIn('id', array_keys($updates))->update(['track_number' => null]);

            foreach ($updates as $songId => $position) {
                DB::table('songs')->where('id', $songId)->update(['track_number' => $position]);
            }
        });
    }

    /**
     * Albums to repair, as a query over their provider mappings.
     *
     * @return Builder<ProviderAlbumMapping>
     */
    private function targets(Provider $record, string $scope): Builder
    {
        $query = ProviderAlbumMapping::query()
            ->with('album')
            ->where('provider_id', $record->getKey())
            ->whereHas('album');

        if ($scope === 'duplicates') {
            /*
             | Albums where two live songs claim the same position. Expressed as
             | a correlated EXISTS over a self-join rather than pulling a list of
             | album ids into PHP first — there are 34,000 albums and the id list
             | for a `whereIn` would be large enough to hit max_allowed_packet,
             | which is exactly how the credits backfill first failed.
             */
            $query->whereExists(function ($exists): void {
                $exists
                    ->from('songs as a')
                    ->join('songs as b', function ($join): void {
                        $join
                            ->on('a.album_id', '=', 'b.album_id')
                            ->on('a.track_number', '=', 'b.track_number')
                            ->on('a.id', '<', 'b.id');
                    })
                    ->whereColumn('a.album_id', 'provider_album_mappings.album_id')
                    ->whereNotNull('a.track_number')
                    ->whereNull('a.deleted_at')
                    ->whereNull('b.deleted_at')
                    ->selectRaw('1');
            });
        } elseif ($scope === 'missing') {
            $query->whereExists(function ($exists): void {
                $exists
                    ->from('songs')
                    ->whereColumn('songs.album_id', 'provider_album_mappings.album_id')
                    ->whereNull('songs.track_number')
                    ->whereNull('songs.deleted_at')
                    ->selectRaw('1');
            });
        }

        return $query;
    }

    /**
     * State the residue rather than implying a clean sweep.
     *
     * An album can survive this with duplicates intact for honest reasons — the
     * provider dropped the release, or returns a tracklist that no longer
     * includes the songs we hold for it. Printing the number keeps a partial
     * repair from reading as a total one.
     */
    private function reportRemainingDuplicates(): void
    {
        $remaining = DB::table('songs as a')
            ->join('songs as b', function ($join): void {
                $join
                    ->on('a.album_id', '=', 'b.album_id')
                    ->on('a.track_number', '=', 'b.track_number')
                    ->on('a.id', '<', 'b.id');
            })
            ->whereNotNull('a.track_number')
            ->whereNull('a.deleted_at')
            ->whereNull('b.deleted_at')
            ->distinct()
            ->count('a.album_id');

        if ($remaining === 0) {
            $this->info('No album has a duplicated track position any more.');

            return;
        }

        $this->warn("{$remaining} album(s) still have a duplicated track position.");
    }
}
