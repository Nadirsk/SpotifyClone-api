<?php

declare(strict_types=1);

namespace App\Services\Providers\JioSaavn;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
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
final class JioSaavnAdapter extends AbstractProviderAdapter
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
     * search into hundreds of outbound calls.
     */
    private const MAX_SEARCH_RESULTS = 200;

    /**
     * Hard cap on pages fetched per search, regardless of $limit or the
     * provider's own `total`. Each page is one more outbound HTTP call (plus
     * throttle wait), and `search()` runs synchronously inline on a search
     * request (SearchService::syncThenRerun()) — enough pages to matter here
     * risks the same PHP execution-time crash a fully synchronous multi-type
     * sync already caused once (see SearchService's docblock).
     */
    private const MAX_SEARCH_PAGES = 5;

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

        foreach ($songs as $item) {
            if (! is_array($item)) {
                continue;
            }

            $song = $this->mapSong($item);

            if ($song !== null) {
                $mapped[] = $song;
            }
        }

        return $mapped;
    }

    /**
     * Walks pages until $limit results are collected, the provider's own
     * `total` is exhausted, a page comes back empty, or MAX_SEARCH_PAGES is
     * reached — whichever happens first. The last one is a hard ceiling on
     * outbound calls per search: a term whose `total` runs into the
     * thousands ("Tum Hi Ho": 1,960) must not turn one search into an
     * unbounded crawl.
     *
     * Deliberately does not stop just because a page returned fewer items
     * than asked — observed in production against the current wrapper: a
     * page=0,limit=50 request for "Ve Kamleya" (total 88) came back with only
     * 40 results despite 48 more genuinely existing on page 1. `total` is the
     * only reliable signal that nothing is left.
     *
     * @return list<array<array-key, mixed>>
     */
    private function search(string $path, string $query, int $limit): array
    {
        $limit = max(1, min(self::MAX_SEARCH_RESULTS, $limit));
        $collected = [];
        $total = null;

        for ($page = 0; $page < self::MAX_SEARCH_PAGES; $page++) {
            if ($total !== null && count($collected) >= $total) {
                break;
            }

            $remaining = $limit - count($collected);

            if ($remaining <= 0) {
                break;
            }

            $payload = $this->fetch($path, [
                'query' => $query,
                'page' => $page,
                'limit' => min(50, $remaining),
            ]);

            if ($payload === null) {
                break;
            }

            $total ??= $this->int($this->dig($payload, 'data.total'));

            $items = $this->dig($payload, 'data.results');

            if (! is_array($items) || $items === []) {
                break;
            }

            $collected = array_merge($collected, $items);
        }

        return array_slice($collected, 0, $limit);
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

    /** @param array<array-key, mixed> $item */
    private function mapSong(array $item): ?ProviderSongData
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
        return $this->singerCredit($item) ?? $this->firstPrimaryArtist($item);
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
