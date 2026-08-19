<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\DTO\Providers\ProviderArtistCredit;
use App\DTO\Providers\ProviderSongData;
use App\Enums\CreditRole;
use App\Models\Artist;
use App\Models\Provider;
use App\Models\ProviderArtistMapping;
use App\Models\Song;
use App\Models\SongCredit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Persists a song's credit list into `song_credits`.
 *
 * Split out of {@see SyncService} rather than inlined there because the backfill
 * command needs exactly this and nothing else around it — it already has the
 * provider payload in hand and must not re-run album resolution, deduplication
 * or the release-announcement side effects that `syncSong()` carries.
 *
 * ## Resolving a credit to a local artist
 *
 * By provider ID first, through `provider_artist_mappings`. That is the only
 * identifier that is stable: the crawler has already walked
 * {@see ProviderSongData::$artistIds} for most of the
 * catalog, so the great majority of credits resolve here without a write.
 *
 * By name second, through {@see MetadataNormalizer::resolveArtist()}, which
 * slugs and creates. Deliberately second: two different people can share a
 * display name and the ID knows the difference, whereas the name does not. Also
 * deliberately *allowed* to create — a lyricist the crawler has never visited
 * is still a real artist, and a credit dropped for want of a row is a credit
 * that will still be missing after the next pass.
 *
 * Newly created artists are given the provider mapping too, so the next payload
 * mentioning them resolves by ID and a later full artist sync enriches the same
 * row instead of making a second one.
 *
 * ## Replace, do not merge
 *
 * A payload's credit list is complete or it is empty — a provider does not hand
 * back half of one. So a non-empty list replaces what is stored, which is what
 * lets a corrected credit actually remove the wrong name, and an empty list
 * leaves the stored set untouched, following the sync engine's rule that a
 * silent provider never erases what a talkative one established
 * (07_SYNC_ENGINE §11).
 *
 * The replace is scoped to the song, not to the provider, because
 * `song_credits` has no provider column. With one provider supplying credits
 * that is exactly right. A second one would need that column first, or the two
 * would take turns deleting each other's work — noted here rather than built,
 * since an unused column is its own kind of wrong.
 */
final class CreditWriter
{
    public function __construct(private readonly MetadataNormalizer $normalizer) {}

    /**
     * @param  list<ProviderArtistCredit>  $credits
     * @return int Number of credit rows the song now has.
     */
    public function write(Song $song, Provider $provider, array $credits): int
    {
        if ($credits === []) {
            return 0;
        }

        $now = Carbon::now();
        $rows = [];
        $seen = [];

        /*
         | The display artist first, unconditionally.
         |
         | `song_credits` is a complete superset of `songs.artist_id` — see
         | SongObserver, and Song::scopeCreditedTo(), which is a single indexed
         | lookup precisely because it can assume that. This method replaces a
         | song's whole credit list, so without seeding the display artist here a
         | provider payload that happens not to credit them would delete the row
         | the observer wrote and drop the song off its own artist's page.
         |
         | Seeded first so that if the provider *does* credit the same person as
         | `primary`, the $seen guard below collapses the two rather than the
         | unique key rejecting the batch.
         */
        if (is_string($song->artist_id) && $song->artist_id !== '') {
            $seen[$song->artist_id.'|'.CreditRole::Primary->value] = true;

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'song_id' => $song->getKey(),
                'artist_id' => $song->artist_id,
                'role' => CreditRole::Primary->value,
                'position' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($credits as $credit) {
            $artist = $this->resolve($credit, $provider);

            if ($artist === null) {
                continue;
            }

            /*
             | Two provider IDs can resolve to one local artist — the catalog
             | deliberately merges duplicate provider entries for the same
             | person — which would otherwise violate the table's
             | (song, artist, role) key mid-insert.
             */
            $key = $artist->getKey().'|'.$credit->role->value;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'song_id' => $song->getKey(),
                'artist_id' => $artist->getKey(),
                'role' => $credit->role->value,
                'position' => $credit->position,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        /*
         | Delete-then-insert rather than an upsert plus a diff. The set is
         | small (a handful of rows per song), it is being replaced wholesale
         | anyway, and this is the only shape that removes a credit the
         | provider has retracted. Both statements run inside the caller's
         | transaction.
         */
        SongCredit::query()->where('song_id', $song->getKey())->delete();
        SongCredit::query()->insert($rows);

        return count($rows);
    }

    private function resolve(ProviderArtistCredit $credit, Provider $provider): ?Artist
    {
        $mapping = ProviderArtistMapping::query()
            ->with('artist')
            ->where('provider_id', $provider->getKey())
            ->where('provider_artist_id', $credit->externalId)
            ->first();

        if ($mapping?->artist !== null) {
            return $mapping->artist;
        }

        if ($credit->name === null) {
            /*
             | An ID the crawler has not reached yet and a name the adapter
             | rejected as a credit line. Nothing here identifies a person, so
             | the credit is skipped rather than guessed at — it will resolve on
             | a later pass, once the artist page behind the ID is synced.
             */
            return null;
        }

        $artist = $this->normalizer->resolveArtist($credit->name);

        if ($artist === null) {
            return null;
        }

        if ($mapping === null) {
            $this->mapNewArtist($artist, $provider, $credit->externalId);
        }

        return $artist;
    }

    /**
     * Record the provider ID for an artist first seen as a credit.
     *
     * `provider_artist_mappings` enforces *two* unique keys — one provider maps
     * an external ID once, and one provider maps a local artist once — so this
     * has to check the second before inserting. It is reached constantly:
     * resolution by name deliberately merges provider IDs that denote the same
     * person, so a second ID for an artist who already has a mapping is the
     * normal case, not an edge one. Inserting blindly would abort the
     * transaction on a duplicate key and lose the whole song's credits.
     *
     * When the artist is already mapped to a different external ID, that
     * mapping is left alone and this one is simply not recorded. The credit
     * itself is still written — it points at the local artist, not at the
     * provider's ID — so nothing is lost but a shortcut.
     */
    private function mapNewArtist(Artist $artist, Provider $provider, string $externalId): void
    {
        $alreadyMapped = ProviderArtistMapping::query()
            ->where('provider_id', $provider->getKey())
            ->where('artist_id', $artist->getKey())
            ->exists();

        if ($alreadyMapped) {
            return;
        }

        ProviderArtistMapping::query()->firstOrCreate(
            [
                'provider_id' => $provider->getKey(),
                'provider_artist_id' => $externalId,
            ],
            [
                'artist_id' => $artist->getKey(),
                // No checksum: nothing has been synced from the artist's own
                // payload yet, so leaving it null keeps the next artist sync
                // from short-circuiting on a hash of data we never wrote.
                'checksum' => null,
            ],
        );
    }
}
