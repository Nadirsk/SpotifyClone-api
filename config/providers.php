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
         | A 429 ignores this curve entirely and honours `Retry-After` instead —
         | the provider knows better than we do when it will serve us again.
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
         | `requests` per `per_seconds`. The adapter sleeps the remainder of the
         | resulting minimum interval before each call. This is a courtesy
         | throttle, not a guarantee: it is per-process, so run the `sync` queue
         | with a single worker for providers whose published limit is strict.
         */
        'rate_limit' => [
            'requests' => 10,
            'per_seconds' => 1,
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

        // Results requested per provider when a search misses locally.
        'lazy_search_limit' => 10,

        /*
         | Minutes a missed search term is remembered before another lazy sync
         | is allowed to fire for it. Without this, a term with genuinely zero
         | local results would queue one LazySyncSearchJob per request while
         | it trends — the debounce caps that at one dispatch per window
         | regardless of how many people search it in the meantime.
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
    | JioSaavn does not publish a developer API. `base_url` points at a
    | community-maintained wrapper by default; swap it for a self-hosted
    | instance for anything beyond light development use.
    |
    */

    'jiosaavn' => [
        'enabled' => (bool) env('JIOSAAVN_ENABLED', false),
        'base_url' => env('JIOSAAVN_BASE_URL', 'https://saavn.dev/api'),
        // Undocumented and unofficial; conservative guess to stay polite.
        'rate_limit' => ['requests' => 5, 'per_seconds' => 1],
    ],
];
