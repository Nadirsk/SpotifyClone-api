<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Diagnostics page key
    |--------------------------------------------------------------------------
    |
    | GET /diagnostics?key=... is checked against this. No value means the
    | page always answers 404, the same as a wrong key - it does not
    | accidentally become public just because nobody set it yet.
    |
    | Generate one with: php artisan tinker --execute="echo Str::random(40);"
    */

    'key' => env('DIAGNOSTICS_KEY'),

];
