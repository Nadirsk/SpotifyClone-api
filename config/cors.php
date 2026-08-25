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

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('ADMIN_URL', 'http://localhost:5174'),
    ],

    /*
     | Vite auto-increments the admin app's port when 5174 is already taken
     | (e.g. two dev servers running locally), so a single fixed ADMIN_URL
     | keeps breaking CORS for no real reason. Local-only, so this is safe.
     */
    'allowed_origins_patterns' => [
        '#^http://localhost:517[0-9]$#',
        '#^http://127\.0\.0\.1:517[0-9]$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
