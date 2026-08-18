<?php

declare(strict_types=1);

namespace App\Contracts\Providers;

use App\DTO\Providers\ProviderPlaylistData;

/**
 * Optional capability: this adapter can serve provider-curated playlists.
 *
 * Kept off {@see ProviderAdapter} deliberately. That interface is the shape
 * *every* provider is reduced to, and playlists are not universal — iTunes'
 * Search API has no playlist concept whatsoever, and MusicBrainz's is a
 * different thing wearing the same word. Widening the base contract would
 * force four adapters to implement stubs that only ever return nothing.
 *
 * Callers check with `instanceof` and skip the work when it is absent, which
 * is how {@see \App\Services\Sync\CatalogCrawler} decides whether a provider
 * contributes playlist targets to the frontier at all.
 *
 * This supersedes the older `method_exists()` capability probe still used for
 * `albumTracks()` (see {@see \App\Console\Commands\BootstrapCatalog}); new
 * capabilities get a real interface so the type checker can see them.
 */
interface SupportsPlaylists
{
    /**
     * Search the provider's playlists.
     *
     * @return list<ProviderPlaylistData> Metadata only — `songs` is empty. Fetching
     *                                    every tracklist inline would turn one search into a request per hit.
     */
    public function searchPlaylists(string $query, int $limit): array;

    /**
     * One playlist and a page of its tracklist.
     *
     * @param  int  $page  Zero-based, matching the provider's own paging.
     * @param  int  $limit  Tracks requested for this page. The provider may return fewer
     *                      than asked for while more still exist — page against
     *                      {@see ProviderPlaylistData::$songCount}, never against the page size.
     */
    public function getPlaylist(string $externalId, int $page = 0, int $limit = 50): ?ProviderPlaylistData;
}
