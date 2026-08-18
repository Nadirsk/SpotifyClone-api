<?php

declare(strict_types=1);

namespace App\Contracts\Providers;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderSongData;
use App\DTO\Providers\ProviderPage;

/**
 * Optional capability: this adapter can enumerate an artist's full discography,
 * which is what makes an exhaustive catalog crawl possible.
 *
 * The base {@see ProviderAdapter} contract is search-and-fetch-by-id only.
 * That is enough to keep known records fresh, but it can never *discover*
 * anything a search term did not already surface — which is why a catalog
 * built from search terms alone plateaus at whatever those terms happened to
 * match. Walking artist → songs/albums → credited artists closes over the
 * reachable catalog instead.
 *
 * Only JioSaavn implements this today. It is a real interface rather than a
 * `method_exists()` probe so {@see \App\Services\Sync\CatalogCrawler} can
 * type-check the capability and skip providers that lack it, rather than
 * discovering the absence at call time.
 *
 * Both methods are paged and both report the provider's own total, because
 * the page size is the provider's to choose and is frequently smaller than
 * requested: JioSaavn caps artist listings at 10 per page and ignores any
 * `limit` sent with them. A caller that stopped on a short page would silently
 * truncate a 4,580-song discography at 10.
 */
interface SupportsCatalogCrawl
{
    /**
     * One page of everything the artist is credited on.
     *
     * @param  int  $page  Zero-based.
     * @param  bool  $newestFirst  Order by release date descending instead of the
     *                             provider's default relevance ordering. This is what makes a
     *                             cheap new-release probe possible: page zero of a
     *                             newest-first listing answers "has anything dropped" in one
     *                             request, where the default ordering buries a new release
     *                             behind whatever is currently popular.
     * @return ProviderPage<ProviderSongData>
     */
    public function artistSongs(string $artistId, int $page = 0, bool $newestFirst = false): ProviderPage;

    /**
     * One page of the artist's albums.
     *
     * @param  int  $page  Zero-based.
     * @return ProviderPage<ProviderAlbumData>
     */
    public function artistAlbums(string $artistId, int $page = 0, bool $newestFirst = false): ProviderPage;

    /**
     * What the provider would play next after a given song.
     *
     * The only discovery source here that is not derived from catalog
     * structure — JioSaavn builds it from listening behaviour, so it reaches
     * long-tail records that no search term and no discography walk ever
     * surfaces. Correspondingly the fastest way to grow the frontier, which is
     * why the crawler leaves it off unless asked.
     *
     * @return list<ProviderSongData>
     */
    public function songSuggestions(string $songId, int $limit = 20): array;

    /**
     * Fetch many songs in one call, for providers that support it.
     *
     * A crawl re-reads huge numbers of song IDs, and one request per ID is the
     * difference between a catalog refresh finishing overnight and not
     * finishing. Implementations that cannot batch may loop internally.
     *
     * @param  list<string>  $externalIds
     * @return list<ProviderSongData>
     */
    public function songsByIds(array $externalIds): array;
}
