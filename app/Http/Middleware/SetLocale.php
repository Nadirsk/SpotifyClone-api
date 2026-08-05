<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the response locale, preferring an explicit user preference over the
 * browser's Accept-Language header.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('app.supported_locales', ['en' => 'English']));

        $locale = $request->user()?->language
            ?? $request->getPreferredLanguage($supported)
            ?? config('app.fallback_locale');

        // Never trust the header blindly — only switch to a locale we ship.
        if (in_array($locale, $supported, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
