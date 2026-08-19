<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Jobs\NotifyFollowersOfRelease;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Provider;
use App\Models\ProviderAlbumMapping;
use App\Models\ProviderArtistMapping;
use App\Models\ProviderSongMapping;
use App\Models\Song;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * The sync pipeline: validate → normalize → deduplicate → upsert entity →
 * upsert provider mapping (07_SYNC_ENGINE §3).
 *
 * Two behaviours are load-bearing:
 *
 * - **Checksum short-circuit.** Every mapping row stores a hash of the last
 *   payload we wrote. When the provider hands back the same content, the entity
 *   write is skipped entirely and only the freshness marker moves. On an hourly
 *   incremental sync the overwhelming majority of records are unchanged, so
 *   this is the difference between a few hundred writes an hour and tens of
 *   thousands.
 *
 * - **Nulls never overwrite.** Updating an existing row applies only the
 *   attributes the provider actually supplied. MusicBrainz knows the ISRC but
 *   no artwork; Spotify the reverse. Merging rather than replacing is what lets
 *   several providers enrich one record instead of fighting over it
 *   (07_SYNC_ENGINE §11).
 *
 * Layering note: this writes through the models directly rather than through a
 * repository. The repository contracts in App\Contracts\Repositories are
 * read-side query objects for the API — none of them expose the upsert
 * primitives sync needs — and inventing write methods there would mean
 * touching files outside this change.
 */
final class SyncService
{
    public function __construct(
        private readonly MetadataNormalizer $normalizer,
        private readonly DeduplicationService $deduplicator,
        private readonly CreditWriter $credits,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Sync a batch of songs, returning how many were written or refreshed.
     *
     * @param  iterable<ProviderSongData>  $records
     */
    public function syncSongs(Provider $provider, iterable $records): int
    {
        $synced = 0;

        foreach ($records as $record) {
            if ($this->syncSong($provider, $record) !== null) {
                $synced++;
            }
        }

        return $synced;
    }

    /** @param iterable<ProviderArtistData> $records */
    public function syncArtists(Provider $provider, iterable $records): int
    {
        $synced = 0;

        foreach ($records as $record) {
            if ($this->syncArtist($provider, $record) !== null) {
                $synced++;
            }
        }

        return $synced;
    }

    /** @param iterable<ProviderAlbumData> $records */
    public function syncAlbums(Provider $provider, iterable $records): int
    {
        $synced = 0;

        foreach ($records as $record) {
            if ($this->syncAlbum($provider, $record) !== null) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Persist one provider song. Returns the local song, or null when the
     * record was rejected by validation.
     */
    public function syncSong(Provider $provider, ProviderSongData $data): ?Song
    {
        if (! $this->songIsValid($data)) {
            return null;
        }

        return DB::transaction(function () use ($provider, $data): ?Song {
            $checksum = $data->checksum();

            // Eager-loaded because Model::preventLazyLoading() is on outside
            // production and the unchanged path reads the relation.
            $existingMapping = ProviderSongMapping::query()
                ->with('song')
                ->where('provider_id', $provider->getKey())
                ->where('provider_song_id', $data->externalId)
                ->first();

            if ($existingMapping !== null && $existingMapping->checksum === $checksum) {
                return $this->skipUnchanged($existingMapping, $existingMapping->song);
            }

            // Songs are NOT NULL on artist_id, so the artist must exist first.
            $artist = $this->normalizer->resolveArtist((string) $data->artist);

            if ($artist === null) {
                $this->reject('song', $data->externalId, $provider, 'artist name could not be resolved');

                return null;
            }

            $album = $this->resolveAlbumForSong($data, $artist);
            $song = $this->deduplicator->findSong($data, $provider, $artist, $album);
            $attributes = $this->normalizer->songAttributes($data, $artist, $album);

            $song = $this->writeEntity(
                Song::class,
                $song,
                $this->withStableAlbumMembership($song, $data, $attributes),
            );

            /*
             | Everyone else on the record. Written after the song row exists
             | (the credit rows point at it) and inside the same transaction, so
             | a song can never be committed with a half-written credit list.
             |
             | Not gated on anything: a payload with no credits is a no-op
             | inside the writer, which is what keeps a provider that does not
             | publish them from clearing another's.
             */
            $this->credits->write($song, $provider, $data->credits);

            $this->writeMapping(
                ProviderSongMapping::class,
                $provider,
                localColumn: 'song_id',
                externalColumn: 'provider_song_id',
                localId: (string) $song->getKey(),
                externalId: $data->externalId,
                checksum: $checksum,
            );

            return $song;
        });
    }

    public function syncArtist(Provider $provider, ProviderArtistData $data): ?Artist
    {
        if (! $this->artistIsValid($data)) {
            return null;
        }

        return DB::transaction(function () use ($provider, $data): ?Artist {
            $checksum = $data->checksum();

            $existingMapping = ProviderArtistMapping::query()
                ->with('artist')
                ->where('provider_id', $provider->getKey())
                ->where('provider_artist_id', $data->externalId)
                ->first();

            if ($existingMapping !== null && $existingMapping->checksum === $checksum) {
                return $this->skipUnchanged($existingMapping, $existingMapping->artist);
            }

            $artist = $this->deduplicator->findArtist($data, $provider);
            $artist = $this->writeEntity(Artist::class, $artist, $this->normalizer->artistAttributes($data));

            $this->writeMapping(
                ProviderArtistMapping::class,
                $provider,
                localColumn: 'artist_id',
                externalColumn: 'provider_artist_id',
                localId: (string) $artist->getKey(),
                externalId: $data->externalId,
                checksum: $checksum,
            );

            return $artist;
        });
    }

    public function syncAlbum(Provider $provider, ProviderAlbumData $data): ?Album
    {
        if (! $this->albumIsValid($data)) {
            return null;
        }

        return DB::transaction(function () use ($provider, $data): ?Album {
            $checksum = $data->checksum();

            $existingMapping = ProviderAlbumMapping::query()
                ->with('album')
                ->where('provider_id', $provider->getKey())
                ->where('provider_album_id', $data->externalId)
                ->first();

            if ($existingMapping !== null && $existingMapping->checksum === $checksum) {
                return $this->skipUnchanged($existingMapping, $existingMapping->album);
            }

            $artist = $this->normalizer->resolveArtist((string) $data->artist);

            if ($artist === null) {
                $this->reject('album', $data->externalId, $provider, 'artist name could not be resolved');

                return null;
            }

            $album = $this->deduplicator->findAlbum($data, $provider, $artist);
            $album = $this->writeEntity(Album::class, $album, $this->normalizer->albumAttributes($data, $artist));

            /*
             | Announce it to the artist's followers, but only the first time
             | this album is ever written — `wasRecentlyCreated` is the only
             | signal that separates a genuine new release from the same album
             | arriving again through a second provider or a refresh. Dispatched
             | after the transaction commits so a rolled-back sync cannot
             | announce a release that does not exist.
             */
            if ($album->wasRecentlyCreated) {
                NotifyFollowersOfRelease::dispatch($artist, $album)->afterCommit();
            }

            $this->writeMapping(
                ProviderAlbumMapping::class,
                $provider,
                localColumn: 'album_id',
                externalColumn: 'provider_album_id',
                localId: (string) $album->getKey(),
                externalId: $data->externalId,
                checksum: $checksum,
            );

            return $album;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Validation (07_SYNC_ENGINE §12)
    |--------------------------------------------------------------------------
    */

    private function songIsValid(ProviderSongData $data): bool
    {
        if (trim($data->title) === '') {
            return $this->invalid('song', $data->externalId, 'empty title');
        }

        if ($data->artist === null || trim($data->artist) === '') {
            return $this->invalid('song', $data->externalId, 'missing artist');
        }

        /*
         | A null duration means the provider did not say — Last.fm's search
         | endpoint never does — and is stored as 0 rather than rejected, since
         | a later provider or a detail fetch usually fills it in. A duration
         | that IS supplied but falls outside the configured range is a real
         | data fault: almost always milliseconds parsed as seconds.
         */
        if ($data->duration !== null) {
            $min = (int) config('providers.sync.min_duration', 1);
            $max = (int) config('providers.sync.max_duration', 21_600);

            if ($data->duration < $min || $data->duration > $max) {
                return $this->invalid('song', $data->externalId, "duration out of range ({$data->duration}s)");
            }
        }

        if (trim($data->externalId) === '') {
            return $this->invalid('song', '(blank)', 'missing external id');
        }

        return true;
    }

    private function artistIsValid(ProviderArtistData $data): bool
    {
        if (trim($data->name) === '') {
            return $this->invalid('artist', $data->externalId, 'empty name');
        }

        if (trim($data->externalId) === '') {
            return $this->invalid('artist', '(blank)', 'missing external id');
        }

        return true;
    }

    private function albumIsValid(ProviderAlbumData $data): bool
    {
        if (trim($data->title) === '') {
            return $this->invalid('album', $data->externalId, 'empty title');
        }

        if ($data->artist === null || trim($data->artist) === '') {
            return $this->invalid('album', $data->externalId, 'missing artist');
        }

        if (trim($data->externalId) === '') {
            return $this->invalid('album', '(blank)', 'missing external id');
        }

        return true;
    }

    private function invalid(string $type, string $externalId, string $reason): bool
    {
        $this->logger->info('Sync rejected an invalid provider record', [
            'type' => $type,
            'external_id' => $externalId,
            'reason' => $reason,
        ]);

        return false;
    }

    private function reject(string $type, string $externalId, Provider $provider, string $reason): void
    {
        $this->logger->warning('Sync could not persist a provider record', [
            'type' => $type,
            'provider' => $provider->api_name,
            'external_id' => $externalId,
            'reason' => $reason,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    */

    /**
     * Create the entity, or merge the supplied attributes into the existing one.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  TModel|null  $existing
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function writeEntity(string $model, ?Model $existing, array $attributes): Model
    {
        $attributes['last_synced_at'] = Carbon::now();

        /*
         | Nulls are dropped on BOTH branches, not just the update one. Several
         | NOT NULL columns (e.g. popularity) have no ->nullable() but do have a
         | migration-level ->default(0); omitting the key lets that default
         | apply, whereas passing an explicit null into create() throws
         | "column cannot be null" outright. This is also what keeps a sparse
         | first sync from writing a literal null into a NOT NULL column.
         */
        $attributes = array_filter(
            $attributes,
            static fn (mixed $value): bool => $value !== null,
        );

        if ($existing === null) {
            /** @var TModel $created */
            $created = $model::query()->create($attributes);

            return $created;
        }

        // Drop the fields this provider had nothing to say about so a sparse
        // payload cannot blank out data a richer provider already gave us.
        $existing->fill($attributes)->save();

        return $existing;
    }

    /**
     * Create or refresh the mapping row that ties a local entity to a
     * provider's own ID (07_SYNC_ENGINE §7).
     *
     * Matched on either unique key the table enforces — (provider, external id)
     * and (provider, local id) — because deduplication can point a new external
     * ID at an entity this provider already maps, and blindly inserting would
     * hit the second constraint.
     *
     * @param  class-string<Model>  $model
     */
    private function writeMapping(
        string $model,
        Provider $provider,
        string $localColumn,
        string $externalColumn,
        string $localId,
        string $externalId,
        string $checksum,
    ): void {
        $mapping = $model::query()
            ->where('provider_id', $provider->getKey())
            ->where(static function ($query) use ($externalColumn, $externalId, $localColumn, $localId): void {
                $query->where($externalColumn, $externalId)
                    ->orWhere($localColumn, $localId);
            })
            ->first();

        $mapping ??= new $model;

        $mapping->fill([
            'provider_id' => $provider->getKey(),
            $localColumn => $localId,
            $externalColumn => $externalId,
            'checksum' => $checksum,
            'last_synced_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Nothing about the record changed, so the entity is left completely alone.
     * Only `last_synced_at` moves, and only on the mapping — a single narrow
     * column update that keeps the incremental sync's "oldest first" ordering
     * honest without touching the catalog table or its indexes.
     *
     * @template TModel of Model
     *
     * @param  TModel|null  $entity
     * @return TModel|null
     */
    private function skipUnchanged(Model $mapping, ?Model $entity): ?Model
    {
        $mapping->forceFill(['last_synced_at' => Carbon::now()])->saveQuietly();

        return $entity;
    }

    /**
     * Keep a song attached to the album a tracklist put it on.
     *
     * ## The bug this fixes
     *
     * `album_id` was rewritten on every sync from whatever `resolveAlbumForSong()`
     * made of the incoming payload's album *name*. That name is authoritative
     * when the song arrived as part of an album's tracklist, and a guess when it
     * arrived from a search result — where JioSaavn frequently reports a single
     * release (`Shararat (From "Dhurandhar")`) for a track that is also on the
     * soundtrack album (`Dhurandhar`), or the reverse.
     *
     * The consequence was an album whose contents changed depending on which
     * crawl path had touched its songs most recently: opening the same album
     * twice showed different tracklists, one of them including a song that
     * belongs to a different album — the reported "album shows songs that aren't
     * on it" bug, reproduced live while browsing.
     *
     * ## The rule
     *
     * A non-null `trackNumber` is the marker of authority: `JioSaavnAdapter`
     * supplies it only from `albumTracks()`, so it means "the provider listed
     * this song, at this position, on this album." Given that, membership is
     * written. Without it, membership is written only when the song has none yet;
     * an existing attachment is left alone.
     *
     * ## And the position
     *
     * When membership *does* legitimately move, `track_number` is cleared rather
     * than carried over — a position is meaningful only relative to the album it
     * was measured on. Leaving it behind is what produced albums with four
     * different tracks all claiming to be track 1, which then sorted in an order
     * matching neither album.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withStableAlbumMembership(?Song $song, ProviderSongData $data, array $attributes): array
    {
        $authoritative = $data->trackNumber !== null;

        // Nothing to protect: a brand new row, or one that has no album yet.
        if ($song === null || $song->album_id === null) {
            return $attributes;
        }

        if (! $authoritative) {
            /*
             | A search-sourced payload has no standing to move this song. Drop
             | both keys — `track_number` too, since a position without the album
             | it belongs to is meaningless.
             */
            unset($attributes['album_id'], $attributes['track_number']);

            return $attributes;
        }

        $incoming = $attributes['album_id'] ?? null;

        if ($incoming !== null && (string) $incoming !== (string) $song->album_id) {
            /*
             | An authoritative move to a different album. `writeEntity()` drops
             | nulls, so the stale position cannot be cleared by passing null —
             | it is zeroed on the model directly, and the new position in
             | $attributes then overwrites it.
             */
            $song->forceFill(['track_number' => null]);
        }

        return $attributes;
    }

    /**
     * Attach the song to its album, creating a stub when we have only the name.
     *
     * No mapping row is written for a stub: the song payload gives us the
     * album's title but not the provider's album ID, and a mapping without a
     * trustworthy external ID would be a lie the next sync has to unpick.
     *
     * The exact-slug lookup runs first because it is the cheap, certain
     * answer; the deduplicator's fuzzy core-title match runs before stubbing
     * a new row because a song's own `album` string frequently lacks a
     * qualifier the real synced album title carries ("Aashiqui 2" vs
     * "Aashiqui 2 (Original Motion Picture Soundtrack)") — without this, that
     * mismatch alone was the single biggest source of duplicate albums,
     * stubbing a near-copy on nearly every song synced against an album that
     * already exists under its fuller title.
     */
    private function resolveAlbumForSong(ProviderSongData $data, Artist $artist): ?Album
    {
        if ($data->album === null || trim($data->album) === '') {
            return null;
        }

        $slug = Str::slug($data->album);

        if ($slug === '') {
            return null;
        }

        /*
         | Language-scoped, like every tier in DeduplicationService::findAlbum().
         | This method is what actually built the 26-track "M.S. Dhoni - The
         | Untold Story" — a Telugu track naming that album fell past the two
         | artist-scoped lookups into the unscoped core-title tier, which
         | compares nothing but the title and so happily returned the Hindi
         | row. See findAlbum()'s docblock for the whole account.
         */
        $language = $this->normalizer->resolveLanguage($data->language);

        $album = Album::query()
            ->where('slug', $slug)
            ->where('artist_id', $artist->getKey())
            ->when(
                $language !== null,
                /*
                 | Rows with no language still match: the column is nullable and
                 | most of this catalog predates it being populated, so requiring
                 | equality would split every one of them on the next sync.
                 */
                static fn ($query) => $query->where(static fn ($q) => $q
                    ->whereNull('language_id')
                    ->orWhere('language_id', $language->getKey())),
            )
            ->first();

        if ($album !== null) {
            return $album;
        }

        $album = $this->deduplicator->albumByCoreTitle($data->album, $artist, $data->language)
            ?? $this->deduplicator->albumByCoreTitleAnyArtist($data->album, $data->language);

        if ($album !== null) {
            return $album;
        }

        /*
         | `language_id` set on creation, not left null. Without it the album
         | this song just created is languageless, every guard above waves the
         | next language through on the nullable-row allowance, and the merge
         | reappears one release later.
         */
        return Album::query()->create([
            'artist_id' => $artist->getKey(),
            'language_id' => $language?->getKey(),
            'title' => trim($data->album),
            'slug' => $slug,
            'cover_image' => $data->image,
            'release_date' => $data->releaseDate,
            'last_synced_at' => Carbon::now(),
        ]);
    }
}
