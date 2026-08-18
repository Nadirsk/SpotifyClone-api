<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| External Metadata Providers
|--------------------------------------------------------------------------
|
| One block per adapter registered in App\Providers\ProviderIntegrationServiceProvider.
| The array key is the adapter's key() and must match the `api_name` column of
| the matching row in the `providers` table (11_PROVIDER_INTEGRATION §10).
|
| Every provider ships disabled. An adapter only ever touches the network when
| its `enabled` flag is true AND its credentials are present, so a fresh
| checkout makes no outbound calls — see docs/DEFERRED.md §4.
|
| Credentials are read from the environment and nothing else. They are never
| logged: App\Services\Providers\AbstractProviderAdapter scrubs them from every
| log context it builds.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Shared Defaults
    |--------------------------------------------------------------------------
    |
    | Any key here can be overridden per provider. AbstractProviderAdapter looks
    | up `providers.<key>.<path>` first and falls back to `providers.defaults.<path>`.
    |
    */

    'defaults' => [

        // Seconds to wait for the full response / for the TCP handshake.
        'timeout' => (int) env('PROVIDER_TIMEOUT', 10),
        'connect_timeout' => (int) env('PROVIDER_CONNECT_TIMEOUT', 5),

        /*
         | Retry with exponential backoff, capped at 5 attempts per
         | 07_SYNC_ENGINE §10. The delay for attempt N is
         | base_delay_ms * 2^(N-1), jittered, and clamped to max_delay_ms so a
         | provider answering 503 for an hour cannot pin a queue worker.
         |
         | A 429 does not use this curve and is never retried in-loop at all —
         | it goes to the cooldown below instead. Retrying a refusal is just
         | collecting the same refusal again, one attempt slower each time.
         */
        'retry' => [
            'max_attempts' => 5,
            'base_delay_ms' => 500,
            'max_delay_ms' => 60_000,
        ],

        /*
         | Circuit breaker (11_PROVIDER_INTEGRATION §8). After this many
         | consecutive failed exchanges the adapter stops issuing requests for
         | `cooldown_seconds`, so a dead provider degrades one sync run instead
         | of burning every worker in the pool on timeouts.
         |
         | `failure_window_seconds` expires the counter, so five failures spread
         | across a day do not add up to an open circuit.
         */
        'circuit_breaker' => [
            'failure_threshold' => 5,
            'failure_window_seconds' => 600,
            'cooldown_seconds' => 300,
        ],

        /*
         | Client-side spacing between requests to one provider, expressed as
         | `requests` per `per_seconds`. Each caller reserves the next free slot
         | in a shared, lock-guarded schedule and sleeps until it comes round,
         | so the spacing holds across parallel queue workers and PHP-FPM
         | processes rather than only within one of them.
         */
        'rate_limit' => [
            'requests' => 10,
            'per_seconds' => 1,

            /*
             | Load shedding. The longest a caller will wait — for its slot, or
             | for a cooldown to lapse — before abandoning the call and letting
             | the local catalog answer alone.
             |
             | Small on purpose: a lazy sync runs inline on a user's search
             | (SearchService::syncThenRerun()), so this is latency a real
             | person sits through. A backed-up provider should make the app
             | slightly less complete, never slow.
             */
            'max_wait_ms' => 2_000,

            /*
             | How long one 429 parks the entire provider, doubling per
             | consecutive 429 up to `cooldown_max_ms` and reset by the first
             | success. Shared across processes, so the cost of discovering a
             | rate limit is paid once rather than by every request that
             | follows. A longer `Retry-After` from the provider wins.
             */
            'cooldown_ms' => 30_000,
            'cooldown_max_ms' => 900_000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    |
    | Tolerances used by App\Services\Sync\DeduplicationService (07_SYNC_ENGINE §6).
    |
    */

    'dedupe' => [
        /*
         | Two recordings of the same title by the same artist whose runtimes
         | differ by at most this many seconds are treated as the same song.
         | Providers disagree by a second or two on fades and gapless tracks;
         | anything wider than this is usually a genuinely different cut
         | (radio edit, live version) and deserves its own row.
         */
        'duration_tolerance_seconds' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    */

    'sync' => [
        /*
         | Incremental sync re-fetches the mappings that have not been touched
         | for this long, oldest first. Providers give us no "changed since"
         | cursor, so freshness is driven off our own last_synced_at.
         */
        'stale_after_hours' => 24,

        // Mappings refreshed per provider per incremental run.
        'batch_size' => 200,

        /*
         | Results requested per provider on a live sync. Set to the adapter's
         | own hard ceiling (JioSaavnAdapter::search() clamps to 50 regardless
         | of what is asked for) rather than a smaller number: JioSaavn's own
         | `total` for a query is frequently well under 50 (a specific song
         | title, an obscure artist), and requesting fewer than that would
         | mean missing real matches the provider already told us exist —
         | not a rate-limit concern, since this is one search call either way,
         | just a bigger page of the same response.
         */
        'lazy_search_limit' => 50,

        /*
         | How many of those results get a follow-up detail call during a lazy
         | sync — `getArtist()` for a bio and follower count, `albumTracks()`
         | for a tracklist.
         |
         | Unlike the search above, this one *is* a rate-limit concern, and was
         | the single biggest source of outbound traffic in the app: one detail
         | call per hit against a 50-result search meant a first-time term cost
         | upwards of a hundred JioSaavn requests, which is how a shared free
         | tier gets exhausted by one developer typing in a search box. Capped
         | at the handful a user is actually likely to click; `catalog:enrich-
         | artists` and the incremental sync backfill the rest off-peak, which
         | is what they are for.
         */
        'detail_fetch_limit' => 5,

        /*
         | Minutes a search term is remembered before another live sync is
         | allowed to fire for it. Every search with a term now calls the
         | provider (SearchService::shouldSyncFromProvider()), not only a
         | local miss, so without this a trending term would hit JioSaavn
         | once per request — the debounce caps that at one live call per
         | window regardless of how many people search it in the meantime.
         */
        'lazy_debounce_minutes' => 15,

        /*
         | Reject a duration outside this range during validation
         | (07_SYNC_ENGINE §12). Zero means the provider did not tell us;
         | six hours is well past any real recording and signals a unit mix-up
         | (milliseconds parsed as seconds).
         */
        'min_duration' => 1,
        'max_duration' => 21_600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog Crawl
    |--------------------------------------------------------------------------
    |
    | The discovery half of the sync engine. `sync` above keeps records we
    | already have fresh; this finds the ones we do not have yet.
    |
    | JioSaavn exposes no "list everything" endpoint, so completeness is
    | reached by transitive closure instead: a seed term surfaces artists,
    | albums and playlists; each artist yields their full song and album
    | pages; each album and playlist yields tracks; every artist credited on
    | those tracks is queued in turn. Run long enough with an empty frontier
    | as the stop condition, that reaches everything reachable.
    |
    | State lives in `catalog_crawl_targets` rather than in the queue payload,
    | so a run is resumable: kill it at any point and the next tick continues
    | from the exact page it stopped on.
    |
    */

    'crawl' => [
        /*
         | Targets claimed per CrawlFrontierJob run. The job takes a lease on
         | each one, so this is also the blast radius of a worker dying
         | mid-run — those leases expire and the targets return to the queue.
         */
        'batch_size' => (int) env('CRAWL_BATCH_SIZE', 25),

        /*
         | How long a claimed target stays leased before another worker may
         | take it. Must comfortably exceed the slowest single target: a
         | prolific artist is hundreds of sequential pages (Arijit Singh:
         | 4,580 songs at 10 per page), and reclaiming one mid-flight would
         | have two workers crawling the same pages.
         */
        'lease_seconds' => (int) env('CRAWL_LEASE_SECONDS', 1_800),

        /*
         | Pages walked per target per visit, for the paged target types
         | (artist songs, artist albums, playlist tracks). A target that hits
         | this ceiling is rescheduled at its next page rather than finished,
         | which is what keeps one 458-page artist from monopolising a worker
         | while the rest of the frontier waits.
         */
        'pages_per_visit' => (int) env('CRAWL_PAGES_PER_VISIT', 40),

        /*
         | Consecutive failures before a target is parked as `failed` and stops
         | being retried. Protects the frontier from a permanently 404ing ID
         | cycling forever.
         */
        'max_attempts' => (int) env('CRAWL_MAX_ATTEMPTS', 5),

        /*
         | Completed targets are revisited after this long to pick up new
         | releases — a new album on an artist, a new track on an editorial
         | playlist. This is the "automatically fetched as they are added"
         | half of the requirement, and it is why targets are marked
         | `completed` with a timestamp rather than deleted.
         */
        'revisit_after_hours' => (int) env('CRAWL_REVISIT_AFTER_HOURS', 72),

        /*
         | Artists and playlists are revisited far more often than that, but
         | only their first page — a new release lands at the top of both
         | listings, so one cheap call per entity detects it. The full re-walk
         | still happens on the slower cadence above.
         */
        'new_release_check_hours' => (int) env('CRAWL_NEW_RELEASE_CHECK_HOURS', 6),

        /*
         | Cap on how many artists one new-release sweep checks, so the sweep
         | stays a bounded tick as the catalog grows into six figures. Ordered
         | by popularity: an active artist ships new music far more often than
         | a long-tail one, and gets checked correspondingly more often.
         */
        'new_release_batch' => (int) env('CRAWL_NEW_RELEASE_BATCH', 200),

        /*
         | Whether discovering a song should queue the artists credited on it.
         | This is the recursive step that makes the crawl unbounded — turn it
         | off to keep the frontier to whatever was explicitly seeded.
         */
        'expand_artists' => (bool) env('CRAWL_EXPAND_ARTISTS', true),

        /*
         | Whether to follow JioSaavn's "play next" stations out from songs
         | already in the catalog (CrawlType::SongSuggestions).
         |
         | Off by default, and not because it is low value — it is the only
         | discovery source here that is not derived from catalog structure, so
         | it reaches long-tail records nothing else finds. It is off because
         | it grows the frontier faster than any other source: every song
         | yields ~20 more songs, each of which is another seed, so switching
         | it on turns a converging crawl into one that effectively never
         | drains. Enable it once the structural crawl has finished.
         */
        'expand_suggestions' => (bool) env('CRAWL_EXPAND_SUGGESTIONS', false),

        // Songs requested per station probe when the above is enabled.
        'suggestion_limit' => (int) env('CRAWL_SUGGESTION_LIMIT', 20),

        /*
         | Shortest search term LazySyncSearchJob will promote into the frontier.
         |
         | Every term anyone searches becomes a seed, which is what lets the
         | catalog eventually hold all results for a query the inline path could
         | only answer 50 of. The cost is that a type-ahead box promotes each
         | keystroke's prefix as well — a single "KGF chapter" search left `K`,
         | `KGF`, `KGF cha` and `KGF chapter` in the frontier, and crawling `K`
         | walks a thousand essentially random records.
         |
         | That is not merely wasted work: search targets sit at the FRONT of
         | the priority order (CrawlType::defaultPriority), so prefix noise is
         | crawled ahead of the real artist and album backlog behind it.
         |
         | Three rather than something larger because this catalog's short words
         | are real — the same reason innodb_ft_min_token_size is 1 on this
         | server. It only excludes terms too short to seed anything useful.
         */
        'min_seed_term_length' => (int) env('CRAWL_MIN_SEED_TERM_LENGTH', 3),

        /*
         | Ceiling on results pulled per search term per type.
         |
         | This is the "search should return everything, not a limited number"
         | requirement, expressed as a number. The provider reports totals in
         | the thousands for broad terms ("tum hi ho": 2,524 songs), and the
         | crawler walks pages until it has them all or hits this. It exists
         | only so a single pathological term cannot become an unbounded walk;
         | JioSaavnAdapter clamps to its own MAX_SEARCH_RESULTS regardless.
         */
        'search_result_cap' => (int) env('CRAWL_SEARCH_RESULT_CAP', 5_000),

        /*
         | Hard ceiling on pending frontier rows. Reaching it stops *enqueueing*
         | (crawling continues, draining what is already queued), so an
         | unbounded closure cannot fill the disk unattended. Null disables it.
         */
        'max_pending' => env('CRAWL_MAX_PENDING') === null
            ? 5_000_000
            : (int) env('CRAWL_MAX_PENDING'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spotify — OAuth 2.0 client credentials
    |--------------------------------------------------------------------------
    */

    'spotify' => [
        'enabled' => (bool) env('SPOTIFY_ENABLED', false),
        'base_url' => env('SPOTIFY_BASE_URL', 'https://api.spotify.com/v1'),
        'token_url' => env('SPOTIFY_TOKEN_URL', 'https://accounts.spotify.com/api/token'),
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        'market' => env('SPOTIFY_MARKET', 'US'),
        // Shave a minute off the advertised expiry so a token cannot lapse mid-flight.
        'token_leeway_seconds' => 60,
        // Spotify publishes no fixed quota; this is a deliberately conservative guess.
        'rate_limit' => ['requests' => 10, 'per_seconds' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Apple Music — developer token (ES256 JWT)
    |--------------------------------------------------------------------------
    */

    'apple' => [
        'enabled' => (bool) env('APPLE_MUSIC_ENABLED', false),
        'base_url' => env('APPLE_MUSIC_BASE_URL', 'https://api.music.apple.com/v1'),
        'team_id' => env('APPLE_MUSIC_TEAM_ID'),
        'key_id' => env('APPLE_MUSIC_KEY_ID'),
        // Contents of the .p8 file. Literal "\n" escapes are accepted.
        'private_key' => env('APPLE_MUSIC_PRIVATE_KEY'),
        'storefront' => env('APPLE_MUSIC_STOREFRONT', 'us'),
        // Apple allows up to six months; a short life limits the blast radius of a leak.
        'token_ttl_seconds' => 43_200,
        'rate_limit' => ['requests' => 10, 'per_seconds' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deezer — public API, no authentication
    |--------------------------------------------------------------------------
    */

    'deezer' => [
        'enabled' => (bool) env('DEEZER_ENABLED', false),
        'base_url' => env('DEEZER_BASE_URL', 'https://api.deezer.com'),
        // Documented quota: 50 requests per 5 seconds per IP.
        'rate_limit' => ['requests' => 50, 'per_seconds' => 5],
    ],

    /*
    |--------------------------------------------------------------------------
    | MusicBrainz — public API, identifying User-Agent required
    |--------------------------------------------------------------------------
    */

    'musicbrainz' => [
        'enabled' => (bool) env('MUSICBRAINZ_ENABLED', false),
        'base_url' => env('MUSICBRAINZ_BASE_URL', 'https://musicbrainz.org/ws/2'),
        /*
         | MusicBrainz blocks clients that do not identify themselves with a
         | contactable User-Agent. Treated as a credential: without it the
         | adapter reports itself unconfigured and never calls out.
         */
        'user_agent' => env('MUSICBRAINZ_USER_AGENT'),
        // Hard published limit: one request per second, averaged.
        'rate_limit' => ['requests' => 1, 'per_seconds' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Last.fm — API key as a query parameter
    |--------------------------------------------------------------------------
    */

    'lastfm' => [
        'enabled' => (bool) env('LASTFM_ENABLED', false),
        'base_url' => env('LASTFM_BASE_URL', 'https://ws.audioscrobbler.com/2.0/'),
        'api_key' => env('LASTFM_API_KEY'),
        'rate_limit' => ['requests' => 5, 'per_seconds' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | JioSaavn — no official public API; community JSON wrapper, no auth
    |--------------------------------------------------------------------------
    |
    | JioSaavn does not publish a developer API, so this talks to the
    | community-maintained wrapper (sumitkolhe/jiosaavn-api), which is checked
    | out and run locally at tools/jiosaavn-api — see that directory's
    | node-server.mjs and the README's "Local provider wrapper" section.
    |
    | Self-hosting is not a preference here, it is what makes a full catalog
    | crawl possible at all. The shared public instance (saavn.sumit.co) is a
    | free-tier Cloudflare Worker whose *daily* request allowance is pooled
    | across everyone pointing at it; once it is gone every endpoint answers
    | `429` with a plain-text `error code: 1027` body and no `Retry-After`
    | until 00:00 UTC. Observed here on 2026-08-15: fine until 09:52 UTC, a
    | wall from then on. A crawl of this catalog is millions of requests, which
    | would exhaust that budget within minutes. The local wrapper still reads
    | JioSaavn's own endpoints — it just does not share a quota with strangers.
    |
    | Set JIOSAAVN_BASE_URL to the public instance to fall back to it; nothing
    | else needs to change, but drop the rate limits back to ~2/s if you do.
    |
    */

    'jiosaavn' => [
        'enabled' => (bool) env('JIOSAAVN_ENABLED', false),
        'base_url' => env('JIOSAAVN_BASE_URL', 'http://127.0.0.1:3500/api'),
        'rate_limit' => [
            /*
             | Sized for the local wrapper, where the only real limit is
             | JioSaavn's own upstream latency (~240ms per call, measured) —
             | there is no quota to protect and no shared tenant to be polite
             | to. The ceiling still exists so a runaway crawl cannot saturate
             | the box or make JioSaavn itself treat us as abusive.
             |
             | Against the public instance this must come back down to ~2/s;
             | see the note above.
             */
            'requests' => (int) env('JIOSAAVN_RATE_LIMIT_REQUESTS', 20),
            'per_seconds' => (int) env('JIOSAAVN_RATE_LIMIT_PER_SECONDS', 1),

            /*
             | Short compared to the shared default: a local wrapper that
             | refuses is a wrapper that is restarting or momentarily wedged,
             | which resolves in seconds — not a daily budget that is gone for
             | hours. Parking the crawler for half an hour over a blip would
             | cost far more than retrying soon does.
             */
            'cooldown_ms' => (int) env('JIOSAAVN_COOLDOWN_MS', 5_000),
            'cooldown_max_ms' => (int) env('JIOSAAVN_COOLDOWN_MAX_MS', 120_000),

            /*
             | The crawler runs in a queue worker, not inline on a user's
             | request, so it can afford to wait for its slot rather than shed
             | the call. The shared 2s default exists to keep a lazy search
             | fast; applying it here would silently drop crawl pages whenever
             | the frontier ran hot, leaving holes the run would report as
             | complete.
             */
            'max_wait_ms' => (int) env('JIOSAAVN_MAX_WAIT_MS', 15_000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | iTunes — Apple's free public Search API, no authentication
    |--------------------------------------------------------------------------
    |
    | Distinct from `apple` above: this is the free, keyless Search API
    | (itunes.apple.com/search), not the paid Apple Music catalog API. It
    | covers songs, albums and artists but publishes no popularity score and
    | only a 30-second preview clip — there is no full-length field to map,
    | unlike JioSaavn's `downloadUrl`.
    |
    */

    'itunes' => [
        'enabled' => (bool) env('ITUNES_ENABLED', false),
        'base_url' => env('ITUNES_BASE_URL', 'https://itunes.apple.com'),
        // The catalog skews Indian film/regional music; IN is the storefront
        // that actually returns it (Apple defaults to US otherwise).
        'country' => env('ITUNES_COUNTRY', 'us'),
        // Apple's search endpoint is undocumented but informally tight;
        // conservative on purpose. Sequential calls only — see class docblock.
        'rate_limit' => ['requests' => 1, 'per_seconds' => 3],
    ],
];
