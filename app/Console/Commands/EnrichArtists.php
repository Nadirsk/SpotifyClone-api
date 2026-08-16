<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ProviderUnavailableException;
use App\Models\Artist;
use App\Services\Providers\ProviderManager;
use App\Services\Sync\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfills bio/image/popularity for artists that exist only as a bare-name
 * stub.
 *
 * Most artists in this catalog were never discovered by their own name — they
 * were created by `MetadataNormalizer::resolveArtist()` as a side effect of
 * syncing a song or album that merely *credits* them ("Tum Hi Ho" pulls in
 * "Mithoon" while the bootstrap term that found it was "Arijit Singh"). That
 * path has only a name to work with, so the row it creates has no bio, no
 * image and no popularity, and — critically — no `provider_artist_mapping`
 * either, which means `SyncArtistsJob`'s refresh never reaches it: that job
 * only refreshes mappings that already exist.
 *
 * This command is the bridge for that gap the same way `catalog:bootstrap` is
 * the bridge for an empty catalog: search each stub's own name, fetch the
 * provider's full artist detail for whichever result is actually the same
 * artist, and sync that — which both fills in the rich fields and, this
 * time, writes the mapping so future incremental syncs pick it up on their
 * own.
 */
final class EnrichArtists extends Command
{
    protected $signature = 'catalog:enrich-artists {--limit=0 : Max artists to process, 0 = all with no bio}';

    protected $description = 'Backfill bio/image/popularity for artists created as a bare-name stub from a song or album sync';

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
            $this->error('No provider is enabled and configured — nothing to enrich.');

            return self::FAILURE;
        }

        // Missing bio is the cheapest reliable signal that a row was never
        // more than a name — every field this command can fill arrives
        // together on the same detail response.
        $query = Artist::query()->whereNull('bio')->orderBy('id');

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $artists = $query->get();
        $this->info("Enriching {$artists->count()} artist(s) with no bio...");

        $enriched = 0;

        foreach ($artists as $artist) {
            /*
             | Two calls per artist against a provider that is parked behind a
             | rate limit is a few thousand guaranteed-null calls on a full
             | backlog, and a "Done. Enriched 0/900" that says nothing about
             | why. Stop at the first artist where no provider will answer.
             */
            if ($this->providers->available() === []) {
                $this->warn('Paused: every provider is rate-limited or has an open circuit. Rerun later to continue.');

                break;
            }

            foreach ($adapters as $adapter) {
                $record = $this->providers->record($adapter->key());

                if ($record === null) {
                    continue;
                }

                try {
                    $match = $adapter->searchArtists($artist->name, 1)[0] ?? null;

                    // Only trust the match if it is the same name, not merely
                    // the closest one the search ranked first — a stub named
                    // "Roy" should not get enriched with a same-search "Roy Kapur".
                    if ($match === null || Str::slug($match->name) !== $artist->slug) {
                        continue;
                    }

                    $detail = $adapter->getArtist($match->externalId);
                } catch (ProviderUnavailableException $exception) {
                    // Availability was checked before this artist, so getting
                    // here means the provider went away mid-backlog. Stop
                    // rather than walk the remaining rows into a closed door.
                    $this->warn("Paused: {$exception->reason} ({$adapter->key()}). Rerun later to continue.");

                    break 2;
                }

                if ($detail === null) {
                    continue;
                }

                $result = $this->sync->syncArtist($record, $detail);

                if ($result !== null && $result->getKey() === $artist->getKey()) {
                    $enriched++;
                    $this->line("  enriched: {$artist->name}");

                    break; // Found on this provider — no need to try the next.
                }
            }
        }

        $this->newLine();
        $this->info("Done. Enriched {$enriched}/{$artists->count()} artist(s).");

        return self::SUCCESS;
    }
}
