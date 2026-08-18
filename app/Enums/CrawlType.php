<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What one row of the crawl frontier tells the crawler to do.
 *
 * Two shapes of target, and the split is deliberate:
 *
 * - **Fan-out targets** ({@see SearchTerm}, {@see Artist}) cost one request or
 *   none, and their whole job is to turn one identifier into several narrower
 *   targets. They always finish in a single visit.
 * - **Walk targets** (everything {@see isPaged()} is true for) each drive
 *   exactly *one* provider listing. That one-listing rule is what makes
 *   `cursor_page` mean something unambiguous: a target that walked two
 *   listings would need to encode which of them page 7 belonged to, and any
 *   encoding of that is a bug waiting for the provider to shift a total
 *   underneath it.
 *
 * Adding a case here is safe — `catalog_crawl_targets.type` is a plain string
 * column precisely so a new kind of work never means an ALTER on a table with
 * millions of rows.
 */
enum CrawlType: string
{
    /**
     * A query to exhaust across every search type the provider offers.
     *
     * Expands into the four `search_*` targets below and completes without
     * making a request of its own. Fanning out rather than looping inline is
     * what lets a term's songs keep being walked while its playlists are
     * still going, and what stops one 10,000-result term from holding a lease
     * for an hour.
     */
    case SearchTerm = 'search_term';

    /** One search listing, walked page by page to the provider's own total. */
    case SearchSongs = 'search_songs';
    case SearchAlbums = 'search_albums';
    case SearchArtists = 'search_artists';
    case SearchPlaylists = 'search_playlists';

    /**
     * Fetch the rich artist record, harvest the entities it names in passing
     * (`topSongs`, `topAlbums`, `singles`, `similarArtists`), and queue the
     * discography walks. One request; never paged.
     */
    case Artist = 'artist';

    /** Walk every page of an artist's songs. */
    case ArtistSongs = 'artist_songs';

    /** Walk every page of an artist's albums. */
    case ArtistAlbums = 'artist_albums';

    /**
     * The cheap new-release probe: page zero of an artist's songs and albums,
     * sorted newest first.
     *
     * A full discography re-walk is hundreds of requests and is worth doing
     * every few days at most. A new release, though, lands at the top of both
     * listings the moment it drops, so two requests answer "has anything
     * changed" for one artist. This is the target type the frequent sweep
     * re-opens; {@see ArtistSongs} is the one the slow sweep does.
     */
    case ArtistLatest = 'artist_latest';

    /** Pull an album's tracklist. One request; the detail embeds every song. */
    case Album = 'album';

    /** Pull a playlist's tracklist, paged. */
    case Playlist = 'playlist';

    /**
     * Ask the provider what it would play after a given song.
     *
     * Reaches records no search term and no discography ever surfaces —
     * JioSaavn's station endpoint is drawn from listening behaviour, not from
     * catalog structure — which makes it the only discovery source here that
     * can find genuinely unlinked long-tail songs. Off by default
     * (`providers.crawl.expand_suggestions`) because it grows the frontier
     * faster than anything else.
     */
    case SongSuggestions = 'song_suggestions';

    /**
     * Whether this type walks pages and therefore carries a meaningful
     * `cursor_page` across visits.
     *
     * The single-visit types are the two fan-outs, the album detail (which
     * embeds its entire tracklist in one response), the latest-probe (page
     * zero by definition) and the suggestion probe (one station call).
     */
    public function isPaged(): bool
    {
        return match ($this) {
            self::SearchSongs, self::SearchAlbums, self::SearchArtists, self::SearchPlaylists,
            self::ArtistSongs, self::ArtistAlbums, self::Playlist => true,
            default => false,
        };
    }

    /**
     * For the four `search_*` cases, the provider search type they walk.
     *
     * Returns the plural the provider's own endpoints use (`/search/songs`),
     * so the adapter needs no second mapping table.
     */
    public function searchType(): ?string
    {
        return match ($this) {
            self::SearchSongs => 'songs',
            self::SearchAlbums => 'albums',
            self::SearchArtists => 'artists',
            self::SearchPlaylists => 'playlists',
            default => null,
        };
    }

    /**
     * Default frontier priority — lower is crawled first.
     *
     * The ordering encodes what a half-finished crawl should look like. Seeds
     * come first because nothing else exists until they run. New-release
     * probes come next and stay near the front forever: they are two requests
     * that answer the question this whole system exists to answer, and
     * burying them behind a 458-page discography would mean a track released
     * today lands next week. Discography walks are last of the discovery
     * types for the mirror-image reason — one prolific artist would otherwise
     * starve every other target behind them.
     */
    public function defaultPriority(): int
    {
        return match ($this) {
            self::SearchTerm => 5,
            self::SearchSongs => 10,
            self::SearchAlbums, self::SearchArtists => 12,
            self::SearchPlaylists => 14,
            self::ArtistLatest => 15,
            /*
             | Ahead of artist detail, even though an artist is the cheaper
             | single request, because the two pools behave completely
             | differently. Playlists are a bounded set discovered only by
             | playlist searches — a few dozen, each finishing in one or two
             | requests — so letting them go first populates an entire entity
             | type in seconds. Artist targets are unbounded and self-feeding:
             | every song crawled names more of them, so the pool grows faster
             | than it drains and there is never a moment when "after the
             | artists" arrives. Behind them, playlists would be starved
             | indefinitely rather than merely delayed.
             */
            self::Playlist => 18,
            self::Artist => 20,
            self::Album => 50,
            self::ArtistAlbums => 60,
            self::ArtistSongs => 70,
            self::SongSuggestions => 90,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
