<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Provider;
use App\Models\ProviderAlbumMapping;
use App\Models\ProviderArtistMapping;
use App\Models\ProviderSongMapping;
use App\Models\Song;
use Illuminate\Support\Str;

/**
 * Decides whether a record a provider just handed us is something we already
 * have, so five providers describing one song produce one row and five mappings
 * rather than five rows.
 *
 * The strategies run in the order 07_SYNC_ENGINE §6 fixes, and the order is the
 * whole point — each step is weaker and more willing to produce a false match
 * than the one above it, so a cheap certain answer must never be skipped for an
 * expensive fuzzy one:
 *
 *   1. ISRC — a globally unique recording code. Authoritative when present.
 *   2. Provider mapping — we have synced this exact external ID before.
 *   3. Title + artist + album — the same record described in words.
 *   4. Duration similarity — same title and artist, runtime within tolerance.
 *
 * Returns the existing local entity, or null when this really is new.
 *
 * Manual review (§6 step 5) is not implemented: it needs an admin surface that
 * is outside the MVP, and until one exists a flag nobody reads is worse than no
 * flag. Ambiguous cases fall through to "new record", which a later merge can
 * fix; the opposite mistake silently welds two distinct songs together.
 */
final class DeduplicationService
{
    /**
     * Match a provider's song against the local catalog.
     *
     * @param  Artist|null  $artist  The resolved local artist, when the caller already has one — it
     *                               narrows steps 3 and 4 from "any artist with this name" to one row.
     */
    public function findSong(ProviderSongData $data, Provider $provider, ?Artist $artist = null, ?Album $album = null): ?Song
    {
        return $this->songByIsrc($data)
            ?? $this->songByMapping($data, $provider)
            ?? $this->songByTitleArtistAlbum($data, $artist, $album)
            ?? $this->songByDuration($data, $artist);
    }

    public function findArtist(ProviderArtistData $data, Provider $provider): ?Artist
    {
        return $this->artistByMapping($data, $provider)
            ?? $this->artistByName($data->name);
    }

    public function findAlbum(ProviderAlbumData $data, Provider $provider, ?Artist $artist = null): ?Album
    {
        return $this->albumByMapping($data, $provider)
            ?? $this->albumByTitleAndArtist($data, $artist);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. ISRC
    |--------------------------------------------------------------------------
    */

    /**
     * The International Standard Recording Code identifies a recording
     * worldwide. When two providers agree on one, they are describing the same
     * master — no further checking is warranted.
     */
    private function songByIsrc(ProviderSongData $data): ?Song
    {
        $isrc = $this->normalizeIsrc($data->isrc);

        if ($isrc === null) {
            return null;
        }

        return Song::query()->where('isrc', $isrc)->first();
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Provider mapping
    |--------------------------------------------------------------------------
    */

    /** We have synced this external ID from this provider before. */
    private function songByMapping(ProviderSongData $data, Provider $provider): ?Song
    {
        // Eager-loaded: Model::preventLazyLoading() is on outside production.
        $mapping = ProviderSongMapping::query()
            ->with('song')
            ->where('provider_id', $provider->getKey())
            ->where('provider_song_id', $data->externalId)
            ->first();

        return $mapping?->song;
    }

    private function artistByMapping(ProviderArtistData $data, Provider $provider): ?Artist
    {
        $mapping = ProviderArtistMapping::query()
            ->with('artist')
            ->where('provider_id', $provider->getKey())
            ->where('provider_artist_id', $data->externalId)
            ->first();

        return $mapping?->artist;
    }

    private function albumByMapping(ProviderAlbumData $data, Provider $provider): ?Album
    {
        $mapping = ProviderAlbumMapping::query()
            ->with('album')
            ->where('provider_id', $provider->getKey())
            ->where('provider_album_id', $data->externalId)
            ->first();

        return $mapping?->album;
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Title + artist + album
    |--------------------------------------------------------------------------
    */

    /**
     * Compare on slugs rather than raw titles: providers differ on casing,
     * punctuation and accents ("Déjà Vu" / "Deja Vu"), and the slug columns are
     * indexed, so this stays a single indexed lookup rather than a scan.
     */
    private function songByTitleArtistAlbum(ProviderSongData $data, ?Artist $artist, ?Album $album): ?Song
    {
        $slug = Str::slug($data->title);

        if ($slug === '') {
            return null;
        }

        $query = Song::query()->where('slug', $slug);

        if ($artist !== null) {
            $query->where('artist_id', $artist->getKey());
        } elseif ($data->artist !== null) {
            $artistSlug = Str::slug($data->artist);
            $query->whereHas('artist', static fn ($q) => $q->where('slug', $artistSlug));
        } else {
            // Without an artist this is just "some song with this title", which
            // is far too weak to merge on.
            return null;
        }

        if ($album !== null) {
            $query->where('album_id', $album->getKey());
        } elseif ($data->album !== null) {
            $albumSlug = Str::slug($data->album);
            $query->whereHas('album', static fn ($q) => $q->where('slug', $albumSlug));
        }

        return $query->first();
    }

    private function albumByTitleAndArtist(ProviderAlbumData $data, ?Artist $artist): ?Album
    {
        $slug = Str::slug($data->title);

        if ($slug === '') {
            return null;
        }

        $query = Album::query()->where('slug', $slug);

        if ($artist !== null) {
            $query->where('artist_id', $artist->getKey());
        } elseif ($data->artist !== null) {
            $artistSlug = Str::slug($data->artist);
            $query->whereHas('artist', static fn ($q) => $q->where('slug', $artistSlug));
        } else {
            return null;
        }

        return $query->first();
    }

    private function artistByName(string $name): ?Artist
    {
        $slug = Str::slug($name);

        return $slug === '' ? null : Artist::query()->where('slug', $slug)->first();
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Duration similarity
    |--------------------------------------------------------------------------
    */

    /**
     * Last resort: same artist, same title, runtime within tolerance.
     *
     * Reached when step 3 failed only because the providers disagree about the
     * album — very common, since one calls a track's home the studio album and
     * another a compilation. Requiring the durations to line up keeps that from
     * merging a radio edit into the album cut.
     *
     * The tolerance is deliberately tight (config `providers.dedupe`): a few
     * seconds covers fade and gapless differences, anything wider is usually a
     * genuinely different recording that deserves its own row.
     */
    private function songByDuration(ProviderSongData $data, ?Artist $artist): ?Song
    {
        if ($data->duration === null || $data->duration <= 0) {
            return null;
        }

        $slug = Str::slug($data->title);

        if ($slug === '') {
            return null;
        }

        $tolerance = max(0, (int) config('providers.dedupe.duration_tolerance_seconds', 3));

        $query = Song::query()
            ->where('slug', $slug)
            ->whereBetween('duration', [$data->duration - $tolerance, $data->duration + $tolerance]);

        if ($artist !== null) {
            $query->where('artist_id', $artist->getKey());
        } elseif ($data->artist !== null) {
            $artistSlug = Str::slug($data->artist);
            $query->whereHas('artist', static fn ($q) => $q->where('slug', $artistSlug));
        } else {
            return null;
        }

        /*
         | Closest runtime first, so the best of several near matches wins.
         | `duration` is UNSIGNED; MySQL/MariaDB promote `duration - ?` to
         | unsigned arithmetic, and whenever the candidate's duration is
         | smaller than the incoming one that underflows and throws
         | "BIGINT UNSIGNED value is out of range" instead of returning a row.
         | Casting to SIGNED first forces signed arithmetic so ABS() actually
         | sees the (possibly negative) difference instead of an underflowed
         | unsigned value.
         */
        return $query
            ->orderByRaw('ABS(CAST(duration AS SIGNED) - ?)', [$data->duration])
            ->first();
    }

    /** Providers punctuate ISRCs inconsistently; compare the bare code. */
    private function normalizeIsrc(?string $isrc): ?string
    {
        if ($isrc === null) {
            return null;
        }

        $isrc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $isrc) ?? '');

        return strlen($isrc) === 12 ? $isrc : null;
    }
}
