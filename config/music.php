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

        /*
        | "Top songs today" is a different question from "trending", and it was
        | previously answered with the trending list — a 7-day decayed score
        | presented under a heading that says today. That is wrong in the one
        | direction that matters: on a quiet morning it shows yesterday's chart
        | as though it were this morning's.
        |
        | So the daily chart is counted, not decayed: plays since local midnight,
        | one song one rank. `min_today` is the point below which a day is too
        | thin to call a chart at all — under it, TrendingService answers with
        | the weekly list and says so, rather than promoting three plays to a
        | "top songs today" shelf.
        */
        'min_today' => 4,
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

        /*
        | How much of a track has to be heard before it counts as a play.
        |
        | Without this, skipping through an album logged a play per track the
        | instant each one started, and every chart derived from this table
        | measured skipping rather than listening. 30 seconds is the industry
        | convention (it is what royalty reporting uses); the percentage clause
        | keeps short tracks reachable, since a 45-second interlude can never
        | accumulate 30 seconds without being played nearly whole.
        |
        | The client stops sending below the threshold and the server rejects
        | anything that arrives below it anyway — a play count is not something
        | to take a client's word for.
        */
        'min_play_seconds' => 30,
        'min_play_fraction' => 0.5,
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

    /*
    |--------------------------------------------------------------------------
    | Listen Together
    |--------------------------------------------------------------------------
    */

    'listening' => [
        // Six characters of the alphabet below is ~1.3 billion codes, which is
        // far more than a room table that empties itself needs, and short
        // enough to read down a phone. See ListeningRoomService::freshCode().
        'code_length' => 6,
        // I, O, 0 and 1 are omitted on purpose: a room code gets read aloud and
        // typed by hand, and those four are the pairs people get wrong.
        'code_alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        // A cap on how many listeners one room fans out to. Every playback
        // change is one broadcast to every member, so this bounds the blast
        // radius of a host holding down the seek bar.
        'max_members' => 20,
        // The room queue mirrors the host player queue, which is itself capped
        // by `playlists.max_tracks`; this is the narrower of the two on purpose,
        // because the whole queue is re-broadcast as a snapshot on every change.
        'max_queue' => 200,
        // A room with nobody in it is closed rather than kept: see
        // ListeningRoomService::leave(). This only covers the rooms whose last
        // member vanished without saying goodbye (a closed laptop), which is
        // why it is measured in hours rather than seconds.
        'idle_expiry_minutes' => 180,
    ],
];
