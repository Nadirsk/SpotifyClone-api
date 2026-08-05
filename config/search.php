<?php

declare(strict_types=1);
use App\Models\Album;
use App\Models\Artist;
use App\Models\Playlist;
use App\Models\Song;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Driver
    |--------------------------------------------------------------------------
    |
    | `database` runs queries against MySQL FULLTEXT indexes and is what the
    | local environment uses today. `elasticsearch` is the production target
    | described in 06_SEARCH_ARCHITECTURE but is not wired up yet — see
    | docs/DEFERRED.md. Everything above the driver talks to
    | App\Contracts\Search\SearchEngine, so swapping this value is the only
    | change the application layer should need.
    |
    */

    'driver' => env('SEARCH_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */

    'drivers' => [

        'database' => [
            /*
             | MySQL ignores FULLTEXT words shorter than innodb_ft_min_token_size
             | (default 3). Queries below this length fall back to a LIKE prefix
             | match so that autocomplete on "Be" still returns something.
             */
            'min_fulltext_term_length' => 3,
        ],

        'elasticsearch' => [
            'hosts' => array_values(array_filter(
                explode(',', (string) env('ELASTICSEARCH_HOSTS', 'http://127.0.0.1:9200'))
            )),
            'index_prefix' => env('ELASTICSEARCH_INDEX_PREFIX', 'music_'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Searchable Types
    |--------------------------------------------------------------------------
    |
    | Maps the `type` query parameter accepted by GET /api/v1/search to the
    | model that serves it.
    |
    */

    'types' => [
        'song' => Song::class,
        'artist' => Artist::class,
        'album' => Album::class,
        'playlist' => Playlist::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Result Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'per_type' => 10,
        'autocomplete' => 10,
    ],
];
