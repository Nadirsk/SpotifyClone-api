<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'pagination' => [
        'default_limit' => 20,
        'max_limit' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTLs
    |--------------------------------------------------------------------------
    |
    | Seconds. Values come from 02_SYSTEM_ARCHITECTURE section 7.
    |
    */

    'cache' => [
        'prefix' => 'music',
        'ttl' => [
            'search' => (int) env('CACHE_TTL_SEARCH', 900),
            'trending' => (int) env('CACHE_TTL_TRENDING', 1800),
            'artist' => (int) env('CACHE_TTL_ARTIST', 3600),
            'album' => (int) env('CACHE_TTL_ALBUM', 3600),
            'song' => (int) env('CACHE_TTL_SONG', 3600),
            'recommendations' => (int) env('CACHE_TTL_RECOMMENDATIONS', 1800),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Requests per minute. Values come from 05_API_SPECIFICATION section 17.
    |
    */

    'rate_limits' => [
        'guest' => (int) env('RATE_LIMIT_GUEST', 60),
        'authenticated' => (int) env('RATE_LIMIT_AUTH', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trending
    |--------------------------------------------------------------------------
    |
    | `window_days` is how far back listening history counts toward the
    | trending score. `half_life_hours` decays older plays so that a burst of
    | recent listens outranks a long tail of old ones.
    |
    */

    'trending' => [
        'window_days' => 7,
        'half_life_hours' => 48,
        'limit' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | A play is only recorded once per song per user inside this window, so
    | seeking or refreshing does not inflate play counts.
    |
    */

    'history' => [
        'dedupe_window_minutes' => 5,
        'retention_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Playlists
    |--------------------------------------------------------------------------
    */

    'playlists' => [
        'max_per_user' => 500,
        'max_tracks' => 1000,
        // How long a generated "Invite collaborators" link stays usable.
        'invite_expiry_days' => 30,
    ],
];
