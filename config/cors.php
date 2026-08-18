<?php

declare(strict_types=1);

/**
 * CORS — the frontend calls this API directly from the browser for
 * everything account-shaped (auth, favorites, history, playlists, profile).
 * The music catalog does not need this: it is proxied server-side through
 * Next.js route handlers and never reaches browser CORS at all.
 *
 * `supports_credentials` stays false: this API is Sanctum bearer-token only
 * (see `bootstrap/app.php`'s comment on why `statefulApi()` is never called),
 * not cookie/session auth, so there is no credentialed request to allow.
 */
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
