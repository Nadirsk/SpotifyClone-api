<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Providers\ProviderAdapter;
use App\DTO\Providers\ProviderArtistData;
use App\Exceptions\ProviderUnavailableException;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\SyncService;
use Illuminate\Console\Command;

/**
 * Seeds the catalog with real provider content by running a curated list of
 * search terms through every enabled adapter.
 *
 * Every other sync path in this app assumes the catalog already has
 * something to refresh: `SyncSongsJob`/`SyncArtistsJob`/`SyncAlbumsJob`
 * refresh existing `provider_*_mappings` rows, and `LazySyncSearchJob` only
 * fires on a live search miss. With an empty mappings table — the state of a
 * fresh checkout — none of those discover anything on their own. This
 * command is the one-off bridge: run it once after enabling a provider, and
 * the incremental jobs + lazy sync take over from there for anything this
 * term list did not already cover.
 *
 * `php artisan catalog:bootstrap` — no arguments; the term list below is
 * deliberately hand-picked for this catalog's Indian/regional focus rather
 * than exposed as a CLI option, since a mistyped ad-hoc term is more likely
 * to pollute the catalog than to usefully extend it.
 */
final class BootstrapCatalog extends Command
{
    protected $signature = 'catalog:bootstrap {--limit=25 : Results requested per term per provider}';

    protected $description = 'Seed the catalog with real data from every enabled provider using a curated search-term list';

    /** @var list<string> */
    private const TERMS = [
        'arijit singh',
        'bollywood hits',
        'romantic hindi songs',
        'punjabi songs',
        'shreya ghoshal',
        '90s bollywood',
        'pritam',
        'indian indie',
        'sachin jigar',
        'atif aslam',
        'kishore kumar',
        'a r rahman',
        'diljit dosanjh',
        'hindi lofi',
        'indian pop',
    ];

    public function __construct(
        private readonly ProviderManager $providers,
        private readonly SyncService $sync,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $adapters = $this->providers->enabled();

        if ($adapters === []) {
            $this->error('No provider is enabled and configured — nothing to bootstrap.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $totals = ['songs' => 0, 'artists' => 0, 'albums' => 0];

        foreach ($adapters as $adapter) {
            $record = $this->providers->record($adapter->key());

            if ($record === null) {
                $this->warn("No providers-table row for '{$adapter->key()}'; skipping.");

                continue;
            }

            /*
             | Only JioSaavn implements this today — its album lookup embeds
             | every song's real data, unlike every other provider's, where a
             | song search and an album search are unrelated calls. Not part
             | of ProviderAdapter (see JioSaavnAdapter::albumTracks()), so this
             | is a capability check, not a contract.
             */
            $fetchesTracks = method_exists($adapter, 'albumTracks');

            $this->info("--- {$adapter->key()} ---");

            foreach (self::TERMS as $term) {
                /*
                 | A provider parked behind a rate limit (or with an open
                 | circuit) answers every call below with an immediate null, so
                 | carrying on would scroll the whole term list past reporting
                 | zeroes — which reads as "this provider has no catalog" rather
                 | than "this provider told us to stop". Say which it is.
                 */
                if (! $adapter->isAvailable()) {
                    $this->warn("  paused: {$adapter->key()} is rate-limited or its circuit is open. Rerun later to continue.");

                    break;
                }

                $songs = 0;
                $artists = 0;
                $albums = 0;
                $albumTracks = 0;

                try {
                    $songs = $this->sync->syncSongs($record, $adapter->searchSongs($term, $limit));
                    $artists = $this->sync->syncArtists($record, $this->detailed($adapter, $term, $limit));

                    foreach ($adapter->searchAlbums($term, $limit) as $albumData) {
                        $album = $this->sync->syncAlbum($record, $albumData);

                        if ($album === null) {
                            continue;
                        }

                        $albums++;

                        if ($fetchesTracks) {
                            $albumTracks += $this->sync->syncSongs($record, $adapter->albumTracks($albumData->externalId));
                        }
                    }
                } catch (ProviderUnavailableException $exception) {
                    /*
                     | The check above only catches a provider that was already
                     | parked when the term started; this catches the far more
                     | likely case of it happening partway through, since one
                     | term is dozens of calls. Everything synced so far is
                     | committed and the run is idempotent, so stopping here
                     | costs nothing but the remaining terms.
                     */
                    $this->warn("  paused mid-term: {$exception->reason} ({$adapter->key()}). Rerun later to continue.");

                    break;
                }

                $totals['songs'] += $songs + $albumTracks;
                $totals['artists'] += $artists;
                $totals['albums'] += $albums;

                $this->line(sprintf(
                    '  %-28s songs=%-3d artists=%-3d albums=%-3d albumTracks=%-3d',
                    $term,
                    $songs,
                    $artists,
                    $albums,
                    $albumTracks,
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Synced %d songs, %d artists, %d albums (checksummed — reruns only write what changed).',
            $totals['songs'],
            $totals['artists'],
            $totals['albums'],
        ));

        return self::SUCCESS;
    }

    /**
     * `searchArtists()` alone only has what a search result carries — on
     * JioSaavn that is a name and a photo, never a bio or a follower count
     * (see `catalog:enrich-artists`, which backfills those for a name
     * discovered any other way). Fetching each hit's full detail here means
     * an artist reached directly by one of this command's own search terms
     * gets the rich record from its very first sync, instead of landing in
     * that backfill queue too.
     *
     * @return list<ProviderArtistData>
     */
    private function detailed(ProviderAdapter $adapter, string $term, int $limit): array
    {
        $detailed = [];

        foreach ($adapter->searchArtists($term, $limit) as $thin) {
            $detailed[] = $adapter->getArtist($thin->externalId) ?? $thin;
        }

        return $detailed;
    }
}
