<?php

declare(strict_types=1);

namespace App\Contracts\Providers;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;

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
 * Implementations must not throw on transport failure. A dead provider, a
 * rate-limited provider and a provider missing credentials all resolve to an
 * empty list or null, so one sick provider degrades a sync run instead of
 * failing it. `authenticate()` is the single exception: it throws when the
 * adapter is enabled but misconfigured, because that is an operator error the
 * logs should not swallow.
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
