<?php

declare(strict_types=1);

namespace App\Services\Providers\JioSaavn;

use App\Contracts\Providers\SupportsCatalogCrawl;
use App\Contracts\Providers\SupportsPlaylists;
use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistCredit;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderPage;
use App\DTO\Providers\ProviderPlaylistData;
use App\DTO\Providers\ProviderSongData;
use App\Enums\CreditRole;
use App\Services\Providers\AbstractProviderAdapter;

/**
 * JioSaavn's catalog, reached through a community-maintained JSON wrapper
 * (JioSaavn publishes no official public developer API). `base_url` is
 * therefore config, not a fixed constant — point it at a self-hosted mirror
 * of the wrapper if the shared public one is not trustworthy for production
 * traffic, per CLAUDE.md "Respect provider rate limits and API terms."
 *
 * Response shape quirks absorbed here:
 *
 * - every endpoint wraps its payload as `{ success, data }`; a false
 *   `success` is this provider's way of saying "no record", so the body has
 *   to be inspected like Deezer's inline `error` object;
 * - `id`-based lookups return the singular resource under `data` for albums
 *   and artists, but a list under `data` for songs;
 * - images and artist bio arrive as arrays of variants rather than a single
 *   URL/string, so the highest-quality entry is picked.
 */
final class JioSaavnAdapter extends AbstractProviderAdapter implements SupportsCatalogCrawl, SupportsPlaylists
{
    /**
     * JioSaavn exposes `playCount` as an unbounded string rather than a
     * bounded score. This ceiling is a rough heuristic — unlike Deezer's
     * documented `rank` scale, JioSaavn publishes no maximum — chosen so
     * that only genuinely viral tracks saturate the schema's 0–100 column.
     */
    private const PLAY_COUNT_CEILING = 50_000_000;

    /**
     * Only the rich `/artists/{id}` response (`getArtist()`) carries either
     * field — `/search/artists` gives neither, so a search-sourced artist
     * still resolves to null popularity here until a detail fetch enriches
     * it. Arijit Singh, the catalog's most-followed artist observed while
     * calibrating this, sits at ~105M; the ceiling is set above that so he
     * does not himself saturate the scale.
     */
    private const FOLLOWER_COUNT_CEILING = 150_000_000;

    /**
     * Defensive ceiling on `search()`'s target result count — independent of
     * whatever a caller's own config asks for (`providers.sync.lazy_search_limit`,
     * `catalog:bootstrap --limit`), so a future config mistake cannot turn one
     * search into an unbounded crawl.
     *
     * Raised from 200 to cover a term exhaustively: JioSaavn reports totals in
     * the thousands for common queries ("tum hi ho": 2,524) and the crawler is
     * explicitly asked to store every result rather than a first page of them.
     * The inline search path does not get anywhere near this — it is bounded
     * separately and much more tightly by `providers.sync.lazy_search_limit`
     * (50, i.e. two pages), because that one runs synchronously inside a user's
     * request. This ceiling only ever binds a background crawl.
     */
    private const MAX_SEARCH_RESULTS = 10_000;

    /**
     * What one search page actually holds, which is not what you asked for.
     *
     * Measured against the live wrapper: `limit=50`, `limit=100` and
     * `limit=200` all return exactly 40 results for a term with thousands
     * available. Requesting more per page does not work, so full coverage is
     * reached by walking pages and nothing else.
     */
    private const SEARCH_PAGE_SIZE = 40;

    /**
     * What one artist-listing page holds. Ten, and no parameter changes it —
     * `limit`, `songCount` and `count` were each tried against
     * `/artists/{id}/songs` and all three returned 10. Arijit Singh's 4,580
     * songs are therefore 458 sequential requests, which is why
     * `providers.crawl.pages_per_visit` exists to spread one artist across
     * several visits instead of pinning a worker to it.
     */
    private const ARTIST_PAGE_SIZE = 10;

    /**
     * Ceiling on IDs per `/songs?ids=` batch. The endpoint takes a
     * comma-separated list; this keeps the URL well inside any proxy's limit
     * while still collapsing 50 lookups into one request.
     */
    private const MAX_IDS_PER_BATCH = 50;

    /**
     * Tracks per playlist page. Requests for 200 and 500 both came back with
     * 50, so this is the provider's ceiling rather than our own politeness.
     */
    private const PLAYLIST_PAGE_SIZE = 50;

    public function key(): string
    {
        return 'jiosaavn';
    }

    /** Public reads: being switched on is the whole configuration. */
    protected function hasCredentials(): bool
    {
        return true;
    }

    /** @return list<ProviderSongData> */
    public function searchSongs(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/songs', $query, $limit),
            fn (array $item): ?ProviderSongData => $this->mapSong($item),
        );
    }

    /** @return list<ProviderArtistData> */
    public function searchArtists(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/artists', $query, $limit),
            fn (array $item): ?ProviderArtistData => $this->mapArtist($item),
        );
    }

    /** @return list<ProviderAlbumData> */
    public function searchAlbums(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/albums', $query, $limit),
            fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item),
        );
    }

    public function getSong(string $externalId): ?ProviderSongData
    {
        $payload = $this->fetch('/songs/'.rawurlencode($externalId));

        if ($payload === null) {
            return null;
        }

        $data = $this->dig($payload, 'data');
        $item = is_array($data) ? $this->dig($data, '0') : null;

        return is_array($item) ? $this->mapSong($item) : null;
    }

    public function getArtist(string $externalId): ?ProviderArtistData
    {
        $payload = $this->fetch('/artists/'.rawurlencode($externalId));
        $data = $payload === null ? null : $this->dig($payload, 'data');

        return is_array($data) ? $this->mapArtist($data) : null;
    }

    public function getAlbum(string $externalId): ?ProviderAlbumData
    {
        $payload = $this->fetch('/albums', ['id' => $externalId]);
        $data = $payload === null ? null : $this->dig($payload, 'data');

        return is_array($data) ? $this->mapAlbum($data) : null;
    }

    /**
     * An album's full tracklist, fetched directly.
     *
     * Not part of `ProviderAdapter` — no DTO in this codebase carries a track
     * list alongside album metadata, `ProviderAlbumData` included, because
     * that is genuinely how every *other* provider's API is shaped: a song
     * search and an album search are separate calls with no overlap. JioSaavn
     * is the exception — `/albums?id=` embeds every song's full real data in
     * the same response `getAlbum()` already calls (`data.songs`). Without
     * this, an album only ever gets the tracks that happen to also surface
     * independently from one of the bootstrap's own song-search terms, which
     * is how most synced albums ended up with zero tracks in practice.
     *
     * @return list<ProviderSongData>
     */
    public function albumTracks(string $externalId): array
    {
        $payload = $this->fetch('/albums', ['id' => $externalId]);
        $songs = $payload === null ? null : $this->dig($payload, 'data.songs');

        if (! is_array($songs)) {
            return [];
        }

        $mapped = [];

        /*
         | The tracklist's own order is the track number. JioSaavn ships no
         | position field on a song object — not in `data.songs`, not in a
         | search hit, not in `/songs/{id}` — so the sequence this endpoint
         | returns them in is the only statement it makes about running order,
         | and it is the same order the JioSaavn app itself displays.
         |
         | The position is the item's index in the payload, not a running count
         | of the ones that mapped successfully. A track we cannot map — no id,
         | no title — is still a track on the real album, so skipping its number
         | is right and renumbering around it would shift every track after it
         | out of step with the record itself.
         */
        $position = 0;

        foreach ($songs as $item) {
            $position++;

            if (! is_array($item)) {
                continue;
            }

            $song = $this->mapSong($item, $position);

            if ($song !== null) {
                $mapped[] = $song;
            }
        }

        return $mapped;
    }

    /**
     * Walks pages until $limit results are collected, the provider's own
     * `total` is exhausted, or a page comes back empty — whichever happens
     * first. $limit is the only bound: pass the provider's total (or simply a
     * very large number, clamped to MAX_SEARCH_RESULTS) to take everything a
     * term has.
     *
     * Deliberately does not stop just because a page returned fewer items
     * than asked. That is not an edge case on this provider, it is the norm:
     * every page caps at SEARCH_PAGE_SIZE (40) no matter what `limit` says,
     * so treating a short page as the end would truncate all but the smallest
     * result set at 40. `total` is the only reliable signal that nothing is
     * left.
     *
     * The page loop is bounded by $limit rather than by a fixed page ceiling.
     * A caller asking for 5,000 results has asked for 125 pages and gets them;
     * the protection against that happening inside a user's request is that
     * the inline path asks for 50 (`providers.sync.lazy_search_limit`), not a
     * cap buried here that would silently cripple the crawler too.
     *
     * @return list<array<array-key, mixed>>
     */
    private function search(string $path, string $query, int $limit): array
    {
        $limit = max(1, min(self::MAX_SEARCH_RESULTS, $limit));
        $collected = [];
        $seen = [];
        $exhaustedPages = 0;

        /*
         | One-based. Pages 0 and 1 return the identical 40 records — measured
         | against the live wrapper for several terms — so a zero-based walk
         | spends its first two requests fetching the same page twice. The
         | playlist endpoint has the same off-by-one, already documented on
         | `getPlaylist()`.
         */
        for ($page = 1; count($collected) < $limit; $page++) {
            $payload = $this->fetch($path, [
                'query' => $query,
                'page' => $page,
                'limit' => self::SEARCH_PAGE_SIZE,
            ]);

            if ($payload === null) {
                break;
            }

            $items = $this->dig($payload, 'data.results');

            if (! is_array($items) || $items === []) {
                break;
            }

            $fresh = 0;

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = $this->str($this->dig($item, 'id'));

                // Keep unidentifiable rows; the mapper rejects them anyway, and
                // they must not be mistaken for duplicates and end the walk.
                if ($id === null || ! isset($seen[$id])) {
                    if ($id !== null) {
                        $seen[$id] = true;
                    }

                    $collected[] = $item;
                    $fresh++;
                }
            }

            /*
             | The real stop condition, and it is not the provider's `total`.
             |
             | JioSaavn's search total is a match count, not a paginable depth.
             | "pritam" reports 5,473 songs and serves 1,038 distinct across a
             | sequential walk, after which every further page repeats records
             | already returned — forever, with no empty page and no change in
             | the total. Paging to the reported figure therefore spends about
             | three quarters of its requests re-fetching the same records and
             | reports having stored five times what it actually stored.
             |
             | Exhaustion is instead detected the only way the provider allows:
             | a page that contributes nothing new. Two in a row rather than one,
             | because a single all-duplicate page happens legitimately mid-walk
             | when the ranking shifts under a concurrent crawl.
             |
             | Everything past this ceiling is still reachable — through artist
             | discographies, album tracklists and playlists, which are paged
             | honestly. Search is a seed for those, not the road to completeness.
             */
            if ($fresh === 0) {
                if (++$exhaustedPages >= 2) {
                    break;
                }

                continue;
            }

            $exhaustedPages = 0;
        }

        return array_slice($collected, 0, $limit);
    }

    /**
     * How many results this provider claims to have for a term, without
     * collecting any of them.
     *
     * One cheap page-zero request that lets a caller ask for exactly the right
     * number afterwards. `searchAll()` uses it so "store every result" does
     * not have to mean "request MAX_SEARCH_RESULTS and hope" — a term with 12
     * matches costs one page, not 250.
     *
     * @param  string  $type  `songs`, `albums`, `artists` or `playlists`.
     */
    public function searchTotal(string $type, string $query): ?int
    {
        $payload = $this->fetch('/search/'.$type, [
            'query' => $query,
            'page' => 0,
            'limit' => 1,
        ]);

        return $payload === null ? null : $this->int($this->dig($payload, 'data.total'));
    }

    /*
    |--------------------------------------------------------------------------
    | Playlists (SupportsPlaylists)
    |--------------------------------------------------------------------------
    */

    /** @return list<ProviderPlaylistData> */
    public function searchPlaylists(string $query, int $limit): array
    {
        return $this->mapAll(
            $this->search('/search/playlists', $query, $limit),
            fn (array $item): ?ProviderPlaylistData => $this->mapPlaylist($item),
        );
    }

    /**
     * One playlist plus a page of its tracks.
     *
     * `$page` is zero-based to match every other paged call in this codebase,
     * but JioSaavn's playlist endpoint is **one-based** and silently clamps:
     * `page=0` and `page=1` return the identical first 50 tracks (verified
     * against playlist 1167751266 — same five IDs both times, a different five
     * at `page=2`). Passing a caller's zero straight through would re-sync page
     * one forever and never reach track 51, so it is translated here rather
     * than left as a trap for each caller to rediscover.
     *
     * `limit` is honoured up to PLAYLIST_PAGE_SIZE and ignored above it, so a
     * 100-track playlist is two requests and no amount of asking makes it one.
     */
    public function getPlaylist(string $externalId, int $page = 0, int $limit = self::PLAYLIST_PAGE_SIZE): ?ProviderPlaylistData
    {
        $payload = $this->fetch('/playlists', [
            'id' => $externalId,
            'page' => max(0, $page) + 1,
            'limit' => max(1, min(self::PLAYLIST_PAGE_SIZE, $limit)),
        ]);

        $data = $payload === null ? null : $this->dig($payload, 'data');

        return is_array($data) ? $this->mapPlaylist($data) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Discography crawl (SupportsCatalogCrawl)
    |--------------------------------------------------------------------------
    */

    /**
     * One page of everything an artist is credited on.
     *
     * This is the endpoint that makes an exhaustive catalog possible: a search
     * for "arijit singh" surfaces a few dozen of his tracks, while this
     * reports 4,580 and will hand over every one of them.
     *
     * @return ProviderPage<ProviderSongData>
     */
    public function artistSongs(string $artistId, int $page = 0, bool $newestFirst = false): ProviderPage
    {
        return $this->artistPage(
            path: '/artists/'.rawurlencode($artistId).'/songs',
            collection: 'songs',
            page: $page,
            newestFirst: $newestFirst,
            mapper: fn (array $item): ?ProviderSongData => $this->mapSong($item),
        );
    }

    /**
     * One page of an artist's albums.
     *
     * @return ProviderPage<ProviderAlbumData>
     */
    public function artistAlbums(string $artistId, int $page = 0, bool $newestFirst = false): ProviderPage
    {
        return $this->artistPage(
            path: '/artists/'.rawurlencode($artistId).'/albums',
            collection: 'albums',
            page: $page,
            newestFirst: $newestFirst,
            mapper: fn (array $item): ?ProviderAlbumData => $this->mapAlbum($item),
        );
    }

    /**
     * Shared shape of the two artist listings: `{ data: { total, <collection>: [...] } }`.
     *
     * `sortBy=latest` needs `sortOrder=desc` to actually mean newest-first —
     * with `asc` the same listing starts at 1999 and 2010 releases. Both are
     * sent together or not at all, since the default (relevance/popularity)
     * ordering is the right one for an exhaustive walk: it is stable across
     * pages, whereas a date-sorted walk shifts under you every time the artist
     * releases something mid-crawl.
     *
     * @template TValue
     *
     * @param  callable(array<array-key, mixed>): (TValue|null)  $mapper
     * @return ProviderPage<TValue>
     */
    private function artistPage(
        string $path,
        string $collection,
        int $page,
        bool $newestFirst,
        callable $mapper,
    ): ProviderPage {
        $query = ['page' => max(0, $page)];

        if ($newestFirst) {
            $query['sortBy'] = 'latest';
            $query['sortOrder'] = 'desc';
        }

        $payload = $this->fetch($path, $query);

        if ($payload === null) {
            return ProviderPage::empty($page);
        }

        $items = $this->dig($payload, 'data.'.$collection);

        return new ProviderPage(
            items: is_array($items) ? $this->mapAll($items, $mapper) : [],
            total: $this->int($this->dig($payload, 'data.total')),
            page: $page,
        );
    }

    /**
     * What JioSaavn would play after this song.
     *
     * Backed by the station endpoint, so the seed must be a song ID the
     * provider recognises as station-worthy; an unknown or non-playable ID
     * answers `success: false` with a JavaScript TypeError as its message
     * rather than an empty list. `fetch()` already treats that as "no record",
     * so it surfaces here as an empty array.
     *
     * @return list<ProviderSongData>
     */
    public function songSuggestions(string $songId, int $limit = 20): array
    {
        $payload = $this->fetch('/songs/'.rawurlencode($songId).'/suggestions', [
            'limit' => max(1, min(50, $limit)),
        ]);

        $data = $payload === null ? null : $this->dig($payload, 'data');

        return is_array($data)
            ? $this->mapAll($data, fn (array $item): ?ProviderSongData => $this->mapSong($item))
            : [];
    }

    /**
     * Fetch many songs per request instead of one.
     *
     * A crawl re-reads song IDs in bulk constantly — refreshing a playlist, a
     * tracklist, a discography — and `/songs?ids=` collapses up to
     * MAX_IDS_PER_BATCH of those into a single call. Chunked internally so
     * callers can pass an arbitrarily long list.
     *
     * @param  list<string>  $externalIds
     * @return list<ProviderSongData>
     */
    public function songsByIds(array $externalIds): array
    {
        $externalIds = array_values(array_unique(array_filter(
            $externalIds,
            static fn (string $id): bool => trim($id) !== '',
        )));

        $songs = [];

        foreach (array_chunk($externalIds, self::MAX_IDS_PER_BATCH) as $chunk) {
            $payload = $this->fetch('/songs', ['ids' => implode(',', $chunk)]);
            $data = $payload === null ? null : $this->dig($payload, 'data');

            if (! is_array($data)) {
                continue;
            }

            foreach ($this->mapAll($data, fn (array $item): ?ProviderSongData => $this->mapSong($item)) as $song) {
                $songs[] = $song;
            }
        }

        return $songs;
    }

    /**
     * The IDs an artist-detail response mentions in passing, for seeding the
     * crawl frontier.
     *
     * `/artists/{id}` already carries `topSongs`, `topAlbums`, `singles` and
     * `similarArtists` inline — up to 40 further entities named in a response
     * the crawler fetched anyway. Harvesting them turns one artist lookup into
     * dozens of new frontier targets at no extra request cost, which is most
     * of what keeps an unbounded crawl fed.
     *
     * @return array{songs: list<string>, albums: list<string>, artists: list<string>}
     */
    public function artistRelatedIds(string $artistId): array
    {
        $payload = $this->fetch('/artists/'.rawurlencode($artistId));
        $data = $payload === null ? null : $this->dig($payload, 'data');

        if (! is_array($data)) {
            return ['songs' => [], 'albums' => [], 'artists' => []];
        }

        return [
            'songs' => $this->idsIn($data, 'topSongs'),
            'albums' => array_values(array_unique(array_merge(
                $this->idsIn($data, 'topAlbums'),
                $this->idsIn($data, 'singles'),
            ))),
            'artists' => $this->idsIn($data, 'similarArtists'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function idsIn(array $data, string $key): array
    {
        $entries = $this->dig($data, $key);

        if (! is_array($entries)) {
            return [];
        }

        $ids = [];

        foreach ($entries as $entry) {
            $id = is_array($entry) ? $this->str($this->dig($entry, 'id')) : null;

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>|null
     */
    private function fetch(string $path, array $query = []): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $payload = $this->get($this->baseUrl().$path, $query);

        if ($payload === null) {
            return null;
        }

        // "No record" arrives as HTTP 200 with `success: false`, not a 404.
        if ($this->dig($payload, 'success') !== true) {
            $this->logFailure('provider_error', $this->baseUrl().$path, [
                'message' => $this->dig($payload, 'message'),
            ]);

            return null;
        }

        return $payload;
    }

    /**
     * @template TValue
     *
     * @param  list<array<array-key, mixed>>  $items
     * @param  callable(array<array-key, mixed>): (TValue|null)  $mapper
     * @return list<TValue>
     */
    private function mapAll(array $items, callable $mapper): array
    {
        $mapped = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = $mapper($item);

            if ($value !== null) {
                $mapped[] = $value;
            }
        }

        return $mapped;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  int|null  $trackNumber  Supplied only by {@see albumTracks()}, which is the one caller
     *                                 that knows the running order — see the comment there.
     */
    private function mapSong(array $item, ?int $trackNumber = null): ?ProviderSongData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $title = $this->decoded($this->dig($item, 'name'));

        if ($externalId === null || $title === null) {
            return null;
        }

        return new ProviderSongData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->primaryArtist($item),
            album: $this->decoded($this->dig($item, 'album.name')),
            // Already seconds, like Deezer — no millisecond conversion needed.
            duration: $this->int($this->dig($item, 'duration')),
            genre: null,
            language: $this->str($this->dig($item, 'language')),
            releaseDate: $this->date($this->dig($item, 'releaseDate') ?? $this->dig($item, 'year')),
            image: $this->bestVariant($this->dig($item, 'image')),
            popularity: $this->playCount($this->dig($item, 'playCount')),
            isrc: null,
            // Genuinely full-length — this wrapper reads JioSaavn's own CDN
            // rather than a sanctioned preview endpoint. See the class docblock.
            previewUrl: $this->bestVariant($this->dig($item, 'downloadUrl')),
            externalUrl: $this->str($this->dig($item, 'url')),
            albumId: $this->str($this->dig($item, 'album.id')),
            artistIds: $this->creditedArtistIds($item),
            credits: $this->credits($item),
            playCount: $this->int($this->dig($item, 'playCount')),
            label: $this->decoded($this->dig($item, 'label')),
            copyright: $this->decoded($this->dig($item, 'copyright')),
            explicit: $this->bool($this->dig($item, 'explicitContent')),
            hasLyrics: $this->bool($this->dig($item, 'hasLyrics')),
            trackNumber: $trackNumber,
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapArtist(array $item): ?ProviderArtistData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $name = $this->decoded($this->dig($item, 'name'));

        if ($externalId === null || $name === null || $this->looksLikeCreditLine($name)) {
            return null;
        }

        return new ProviderArtistData(
            provider: $this->key(),
            externalId: $externalId,
            name: $name,
            genre: null,
            image: $this->bestVariant($this->dig($item, 'image')),
            bio: $this->decoded($this->dig($item, 'bio.0.text')),
            country: null,
            popularity: $this->followerCount($item),
            externalUrl: $this->str($this->dig($item, 'url')),
            /*
             | The raw number this time, not the rescaled one, and `fanCount`
             | is the fallback for the same reason `followerCount()` prefers it
             | in reverse: the two fields are populated inconsistently per
             | artist, and whichever is present is the real audience size.
             */
            followerCount: $this->int($this->dig($item, 'followerCount'))
                ?? $this->int($this->dig($item, 'fanCount')),
            isVerified: $this->bool($this->dig($item, 'isVerified')),
            dominantLanguage: $this->str($this->dig($item, 'dominantLanguage')) ?: null,
            dominantType: $this->str($this->dig($item, 'dominantType')) ?: null,
            // Empty strings are this provider's "not set" across all four of these.
            birthDate: $this->str($this->dig($item, 'dob')) ?: null,
            facebookUrl: $this->str($this->dig($item, 'fb')) ?: null,
            twitterUrl: $this->str($this->dig($item, 'twitter')) ?: null,
            wikiUrl: $this->str($this->dig($item, 'wiki')) ?: null,
            availableLanguages: $this->stringList($this->dig($item, 'availableLanguages')),
        );
    }

    /** @param array<array-key, mixed> $item */
    private function mapAlbum(array $item): ?ProviderAlbumData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $title = $this->decoded($this->dig($item, 'name'));

        if ($externalId === null || $title === null) {
            return null;
        }

        return new ProviderAlbumData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            artist: $this->primaryArtist($item),
            genre: null,
            language: $this->str($this->dig($item, 'language')),
            releaseDate: $this->date($this->dig($item, 'releaseDate') ?? $this->dig($item, 'year')),
            image: $this->bestVariant($this->dig($item, 'image')),
            totalTracks: $this->int($this->dig($item, 'songCount')),
            popularity: $this->playCount($this->dig($item, 'playCount')),
            externalUrl: $this->str($this->dig($item, 'url')),
            artistIds: $this->creditedArtistIds($item),
            description: $this->decoded($this->dig($item, 'description')),
            playCount: $this->int($this->dig($item, 'playCount')),
            explicit: $this->bool($this->dig($item, 'explicitContent')),
        );
    }

    /**
     * Maps both playlist shapes the provider returns: a `/search/playlists`
     * hit (metadata only) and a `/playlists?id=` detail (metadata plus a page
     * of real song records).
     *
     * The `songCount` handling is the load-bearing part, and it is not
     * symmetric between the two:
     *
     * - On a **search hit** the field is the playlist's genuine track total
     *   ("Bollywood Bappa": 21) and is kept.
     * - On a **detail** response it is not a total at all — it mirrors however
     *   many tracks that page happened to return. The same playlist reports
     *   `songCount` 1, 5 and 20 for `limit` 1, 5 and 100. Storing that would
     *   give every playlist a track count equal to the last page size fetched,
     *   and any caller paging against it would stop after one page.
     *
     * A detail response is told apart by carrying a `songs` key at all, and
     * has its count dropped to null. Callers page a playlist until a page
     * comes back empty instead — see PlaylistSyncService.
     */
    private function mapPlaylist(array $item): ?ProviderPlaylistData
    {
        $externalId = $this->str($this->dig($item, 'id'));
        $title = $this->decoded($this->dig($item, 'name'));

        if ($externalId === null || $title === null) {
            return null;
        }

        $rawSongs = $this->dig($item, 'songs');
        $isDetail = is_array($rawSongs);

        $songs = $isDetail
            ? $this->mapAll($rawSongs, fn (array $song): ?ProviderSongData => $this->mapSong($song))
            : [];

        $description = $this->decoded($this->dig($item, 'description'));

        return new ProviderPlaylistData(
            provider: $this->key(),
            externalId: $externalId,
            title: $title,
            // Editorial descriptions arrive padded with long runs of spaces
            // and stray newlines from the CMS; collapse them to one clean line.
            description: $description === null ? null : (preg_replace('/\s+/u', ' ', trim($description)) ?: null),
            image: $this->bestVariant($this->dig($item, 'image')),
            // An empty string is the provider's "not set" for playlists.
            language: $this->str($this->dig($item, 'language')) ?: null,
            songCount: $isDetail ? null : $this->int($this->dig($item, 'songCount')),
            followerCount: $this->int($this->dig($item, 'followerCount')),
            externalUrl: $this->str($this->dig($item, 'url')),
            songs: $songs,
        );
    }

    /**
     * JioSaavn returns both images and audio bitrates as
     * `[{quality: "50x50"|"320kbps", url: "..."}, ...]` ordered smallest/lowest
     * first; the last entry is the best.
     *
     * Two distinct "no real photo" placeholders get filtered out here, both
     * observed on `/artists/{id}` for a name that is not actually an artist
     * (a search whose query happens to match a multi-artist credit line as if
     * it were one person's name — see `looksLikeCreditLine()`):
     *
     * - `www.jiosaavn.com/_i/3.0/artist-default-*.png` — not on the
     *   `saavncdn.com` CDN real artwork and audio always come from, so it is
     *   already excluded by the domain check below.
     * - `static.saavncdn.com/_i/share-image-*.png` — a generic site-wide
     *   share/OG image, technically on a `saavncdn.com` subdomain so the
     *   domain check alone does not catch it. Storing this as if it were the
     *   artist's photo also crashed the frontend outright: next/image only
     *   pre-approves specific hostnames, and `static.saavncdn.com` was never
     *   one of them (real artwork only ever comes from `c.saavncdn.com`).
     */
    private function bestVariant(mixed $variants): ?string
    {
        if (! is_array($variants) || $variants === []) {
            return null;
        }

        $last = end($variants);
        $url = is_array($last) ? $this->str($this->dig($last, 'url')) : null;

        if ($url === null || ! str_contains($url, 'saavncdn.com') || str_contains($url, 'static.saavncdn.com')) {
            return null;
        }

        return $url;
    }

    /**
     * Every artist the provider credits on a song or album, by ID.
     *
     * `artists.all` rather than `artists.primary`: the point of collecting
     * these is discovery, and the featured vocalist, the composer and the
     * lyricist are each an artist with their own discography worth crawling.
     * Narrowing to primary credits would make the closure miss exactly the
     * collaborators that link one corner of the catalog to another.
     *
     * Names are not checked against {@see looksLikeCreditLine()} here the way
     * {@see primaryArtist()} does, because that check exists to stop a bad
     * *name* being written to a row. These are IDs, and an ID resolves to
     * whatever the provider's own artist page says — the crawler fetches that
     * page rather than trusting the credit string.
     *
     * @param  array<array-key, mixed>  $item
     * @return list<string>
     */
    private function creditedArtistIds(array $item): array
    {
        $ids = [];

        foreach (['artists.all.*.id', 'artists.primary.*.id', 'primaryArtistsId'] as $path) {
            $found = $this->dig($item, $path);

            if (is_string($found)) {
                // `primaryArtistsId` is a comma-separated string on some shapes.
                $found = explode(',', $found);
            }

            if (! is_array($found)) {
                continue;
            }

            foreach ($found as $id) {
                $id = $this->str($id);

                if ($id !== null && trim($id) !== '') {
                    $ids[trim($id)] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * The wrapper types some flags as real booleans (`explicitContent`) and
     * others as the strings JioSaavn sent (`"true"`, `"0"`), so neither a cast
     * nor a strict comparison is safe on its own.
     *
     * Null is preserved rather than defaulted to false: SyncService drops null
     * attributes before writing, so "the provider did not say" leaves whatever
     * a previous sync established, while a real `false` overwrites it.
     */
    private function bool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            $entry = $this->str($entry);

            if ($entry !== null && trim($entry) !== '') {
                $strings[] = trim($entry);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * JioSaavn's text fields arrive with literal HTML entities —
     * `Tum Hi Ho (From &quot;Aashiqui 2&quot;)` — rather than the real
     * characters. Decoded here, once, rather than at every caller downstream,
     * since this is stored verbatim in `songs`/`albums`/`artists` and every
     * consumer of that data should see real text.
     */
    private function decoded(mixed $value): ?string
    {
        $value = $this->str($value);

        return $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    }

    /**
     * `/search/artists` frequently answers with a whole production credit
     * line rather than one artist — `"Sachin-Jigar|Arijit Singh"`,
     * `"Javed - Mohsin, Arijit Singh & Shreya Ghoshal"`, even
     * `"Arijit Singh Tarannum Malik Earl Edgar D Raja Hasan"` with no
     * separator at all — because the same string partial-matches the query
     * against several people credited on the same song at once. Genuine duo
     * acts in this catalog are hyphenated ("Sachin-Jigar", "Salim-Sulaiman")
     * and untouched by any of this; every observed `&`-joined result instead
     * paired the query name with a different second name per hit, which is
     * "two people credited on one track", not one act with a stable name —
     * so any `&` is treated the same as a comma or pipe. The word-count
     * check catches the residual case with no separator at all
     * ("Arijit Singh Tarannum Malik Earl Edgar D Raja Hasan").
     */
    private function looksLikeCreditLine(string $name): bool
    {
        return str_contains($name, '|')
            || str_contains($name, ',')
            || str_contains($name, '&')
            || count(preg_split('/\s+/', trim($name)) ?: []) > 6;
    }

    /**
     * Every credit on a song, with the role and the name — not just the ID.
     *
     * {@see creditedArtistIds()} above reads the same three places in the
     * payload and throws away everything except the IDs, because all it is for
     * is telling the crawler which artist pages to visit. That is why the
     * catalog has had no notion of a featured vocalist or a composer: the
     * information was arriving on every single song payload, being parsed, and
     * then discarded for want of somewhere to put it.
     *
     * Three places, because no one of them is complete:
     *
     * - `artists.primary` carries the headline names but tags them all
     *   `primary_artists`, so it cannot say who sang and who wrote.
     * - `artists.all` carries the real roles — `singer`, `music`, `lyricist`,
     *   `starring` — but frequently omits the headline singer entirely.
     *   Observed on "Apna Bana Le": `all` credits Sachin-Jigar (music),
     *   Amitabh Bhattacharya (lyricist) and two actors, and does NOT mention
     *   Arijit Singh, who is the person singing it. He is in `primary`.
     * - `artists.featured` is usually empty and is the only place a guest
     *   credit appears when it is not.
     *
     * The union of the three is the credit list. A person legitimately holds
     * two roles on one track, so entries are keyed by ID *and* role and only an
     * exact repeat collapses.
     *
     * Names go through {@see looksLikeCreditLine()} — unlike in
     * `creditedArtistIds()`, where they are not needed — because a name that is
     * really a whole credit line ("Sachin-Jigar|Arijit Singh") would otherwise
     * be created as an artist in its own right by the backfill. The ID survives
     * a rejected name: the credit still links correctly whenever that provider
     * ID is already mapped to a local artist.
     *
     * Sorted before returning, by role then ID. The provider reorders its own
     * arrays between requests for the same track, and the result is folded into
     * {@see ProviderSongData::checksum()} — unsorted, every refresh would look
     * like a change and rewrite the whole catalog.
     *
     * @param  array<array-key, mixed>  $item
     * @return list<ProviderArtistCredit>
     */
    private function credits(array $item): array
    {
        $credits = [];

        foreach (['artists.primary' => 'primary_artists', 'artists.featured' => 'featured_artists', 'artists.all' => null] as $path => $defaultRole) {
            $entries = $this->dig($item, $path);

            if (! is_array($entries)) {
                continue;
            }

            $position = [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $externalId = $this->str($this->dig($entry, 'id'));
                $role = CreditRole::fromProvider($this->str($this->dig($entry, 'role')) ?? $defaultRole);

                if ($externalId === null || trim($externalId) === '' || $role === null) {
                    continue;
                }

                $name = $this->decoded($this->dig($entry, 'name'));

                if ($name !== null && $this->looksLikeCreditLine($name)) {
                    $name = null;
                }

                $position[$role->value] = ($position[$role->value] ?? -1) + 1;

                $credit = new ProviderArtistCredit(
                    externalId: trim($externalId),
                    role: $role,
                    name: $name,
                    position: $position[$role->value],
                );

                /*
                 | First mention wins. `artists.primary` is read first and is
                 | the one shape whose names are reliably a single person, so
                 | when the same ID appears again under `all` with the same
                 | role the earlier, cleaner name is kept.
                 */
                $credits[$credit->key()] ??= $credit;
            }
        }

        $credits = array_values($credits);

        usort($credits, static fn (ProviderArtistCredit $a, ProviderArtistCredit $b): int => [$a->role->weight(), $a->position, $a->externalId] <=> [$b->role->weight(), $b->position, $b->externalId]);

        return $credits;
    }

    /**
     * A song or album's primary-credited artist, skipping over any entry that
     * is itself a credit line.
     *
     * Prefers whoever `artists.all` tags with `role: "singer"` over the first
     * name in `artists.primary`: the same recording re-surfaces under several
     * JioSaavn IDs (once per compilation album it was licensed into), and
     * `artists.primary`'s own ordering is not consistent across those
     * duplicates — one "Tum Hi Ho" listing puts the composer (Mithoon) first,
     * another puts the singer (Arijit Singh) first, for the literal same
     * track. That inconsistency used to resolve the two listings to two
     * different local artists, which defeated every artist-scoped dedupe tier
     * in `DeduplicationService` and produced duplicate songs. The `singer`
     * role on `artists.all` named the same person on both listings.
     */
    private function primaryArtist(array $item): ?string
    {
        return $this->singerCredit($item)
            ?? $this->performingPrimaryArtist($item)
            ?? $this->firstPrimaryArtist($item);
    }

    /**
     * The first `artists.primary` entry who is not credited purely off-mic.
     *
     * Sits between the two older rules because both were wrong for a common
     * shape. "Apna Bana Le" carries no `singer` role at all, so `singerCredit()`
     * returns null and the fallback took `artists.primary[0]` — Amitabh
     * Bhattacharya, its *lyricist*. The song was therefore filed and displayed
     * under the lyricist rather than Arijit Singh, who sings it, and appeared on
     * the wrong artist's page.
     *
     * The provider does not state who performed it. But it does state who wrote
     * the words (`lyricist`), who wrote the music (`music`) and who acted in the
     * film (`starring`), and `artists.primary` is a short list of the people who
     * headline the track. Someone on that list carrying none of those three
     * roles is, by elimination, on it for performing. That is the pick.
     *
     * Only reached when no explicit `singer` credit exists, so it never
     * overrules the provider actually saying who sang.
     *
     * @param  array<array-key, mixed>  $item
     */
    private function performingPrimaryArtist(array $item): ?string
    {
        $offMic = [];

        foreach ($this->credits($item) as $credit) {
            if (in_array($credit->role, [CreditRole::Lyricist, CreditRole::Composer, CreditRole::Actor], true)) {
                $offMic[$credit->externalId] = true;
            }
        }

        if ($offMic === []) {
            // Nothing to eliminate on, so this rule has no opinion and the
            // caller's own fallback is as good as anything here.
            return null;
        }

        $primaries = $this->dig($item, 'artists.primary');

        if (! is_array($primaries)) {
            return null;
        }

        foreach ($primaries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $externalId = $this->str($this->dig($entry, 'id'));

            if ($externalId !== null && isset($offMic[trim($externalId)])) {
                continue;
            }

            $name = $this->decoded($this->dig($entry, 'name'));

            if ($name !== null && ! $this->looksLikeCreditLine($name)) {
                return $name;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $item */
    private function singerCredit(array $item): ?string
    {
        $all = $this->dig($item, 'artists.all');

        if (! is_array($all)) {
            return null;
        }

        foreach ($all as $entry) {
            if (! is_array($entry) || $this->str($this->dig($entry, 'role')) !== 'singer') {
                continue;
            }

            $name = $this->decoded($this->dig($entry, 'name'));

            if ($name !== null && ! $this->looksLikeCreditLine($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Fallback when no `singer`-tagged entry exists (instrumentals, or an
     * album payload that carries no `artists.all` at all).
     *
     * `artists.primary.0.name` is not immune to the same quirk
     * `looksLikeCreditLine()` exists for: at least one synced song carried
     * "Sachin-Jigar|Arijit Singh" as a single `primary` array element, even
     * though `/songs/{id}` for that same track lists "Sachin-Jigar" and
     * "Arijit Singh" as two separate, clean entries. The clean names are
     * usually still in the array — just not always first — so this scans
     * for one instead of trusting index 0.
     */
    private function firstPrimaryArtist(array $item): ?string
    {
        $names = $this->dig($item, 'artists.primary.*.name');

        if (! is_array($names)) {
            return null;
        }

        foreach ($names as $name) {
            $name = $this->decoded($name);

            if ($name !== null && ! $this->looksLikeCreditLine($name)) {
                return $name;
            }
        }

        return null;
    }

    /** Rescale JioSaavn's unbounded `playCount` onto the schema's 0–100 popularity. */
    private function playCount(mixed $playCount): ?int
    {
        $playCount = $this->int($playCount);

        return $playCount === null ? null : $this->popularity((int) round($playCount / self::PLAY_COUNT_CEILING * 100));
    }

    /**
     * Rescale JioSaavn's unbounded `followerCount`/`fanCount` onto the
     * schema's 0–100 popularity, preferring the larger, more consistently
     * present `followerCount`.
     */
    private function followerCount(array $item): ?int
    {
        $followers = $this->int($this->dig($item, 'followerCount')) ?? $this->int($this->dig($item, 'fanCount'));

        return $followers === null ? null : $this->popularity((int) round($followers / self::FOLLOWER_COUNT_CEILING * 100));
    }
}
