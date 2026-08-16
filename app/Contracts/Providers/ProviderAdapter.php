<?php

declare(strict_types=1);

namespace App\Contracts\Providers;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Exceptions\ProviderUnavailableException;

/**
 * The one shape every external metadata provider is reduced to
 * (11_PROVIDER_INTEGRATION §4).
 *
 * Everything above this interface — the sync engine, the jobs, the search
 * fallback — is written against it and knows nothing about Spotify's `items`
 * envelope, Deezer's `data` envelope or MusicBrainz's MBIDs. Adapters return
 * normalized DTOs, never raw provider payloads, so a provider schema can never
 * reach the catalog tables or a client.
 *
 * Implementations answer in exactly one of three ways, and callers must treat
 * the last two differently:
 *
 * 1. **Data** — the provider had the record.
 * 2. **Empty list / null** — the provider answered, and there is no such
 *    record. Believe it.
 * 3. **{@see ProviderUnavailableException}** — the provider never answered:
 *    rate limited, circuit open, or unreachable after every retry. This says
 *    nothing about whether the record exists, so a caller must fall back to
 *    local data and must never persist or cache it as an absence.
 *
 * Callers are expected to catch (3) at the provider boundary. Nothing above
 * this interface — no controller, and certainly no listener pressing play —
 * should ever be able to tell that a metadata provider is having a bad day.
 *
 * `authenticate()` throws separately when the adapter is enabled but
 * misconfigured, because that is an operator error the logs should not swallow.
 */
interface ProviderAdapter
{
    /**
     * Stable identifier for this adapter. Matches both the config block in
     * config/providers.php and the `api_name` column of the `providers` table.
     */
    public function key(): string;

    /**
     * True only when the provider is switched on in config AND every credential
     * it needs is present. Guards every outbound call.
     */
    public function isEnabled(): bool;

    /**
     * True when the provider is enabled *and* would actually answer right now —
     * its circuit is closed and it is not parked after a rate limit.
     *
     * Distinct from `isEnabled()` because it changes minute to minute rather
     * than per deploy. Callers use it to avoid *scheduling* work that would only
     * be suppressed: a fetch is free to attempt this and find out, but queueing
     * a job, or spending a once-per-15-minutes debounce slot, is not.
     */
    public function isAvailable(): bool;

    /**
     * Acquire (or refresh) whatever credential the provider needs for
     * subsequent calls. A no-op for public APIs.
     *
     * @throws \RuntimeException when the provider is enabled but not configured.
     */
    public function authenticate(): void;

    /** @return list<ProviderSongData> */
    public function searchSongs(string $query, int $limit): array;

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array;

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array;

    /** @param string $externalId The provider's own ID — never leaves the mapping tables. */
    public function getSong(string $externalId): ?ProviderSongData;

    public function getArtist(string $externalId): ?ProviderArtistData;

    public function getAlbum(string $externalId): ?ProviderAlbumData;
}
