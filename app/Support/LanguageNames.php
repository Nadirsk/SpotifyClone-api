<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one place that knows how a language is spelled.
 *
 * Extracted from `MetadataNormalizer`, which owned both maps privately, once a
 * second caller needed them: `LanguageTermExtractor` has to recognise "hindi"
 * in a search box, and `DeduplicationService` has to tell a Hindi album from
 * its Telugu namesake. Three copies of a thirty-entry lookup table is how they
 * drift apart, and a code the sync writes but the search cannot read is
 * indistinguishable from a bug.
 *
 * Pure lookups only — no database. `MetadataNormalizer::resolveLanguage()` is
 * still the only thing that creates a `languages` row, because only the sync
 * has any business doing so; dedup and search need to *compare* codes, and
 * creating a lookup row as a side effect of reading a search box would be a
 * write nobody asked for.
 */
final class LanguageNames
{
    /**
     * English names to ISO 639-1 codes, for providers that report a language by
     * name instead of a code — and for a listener who types one.
     *
     * @var array<string, string>
     */
    public const NAMES_TO_CODE = [
        'english' => 'en', 'spanish' => 'es', 'french' => 'fr', 'german' => 'de', 'italian' => 'it',
        'portuguese' => 'pt', 'dutch' => 'nl', 'russian' => 'ru', 'japanese' => 'ja', 'korean' => 'ko',
        'chinese' => 'zh', 'mandarin' => 'zh', 'arabic' => 'ar', 'hindi' => 'hi', 'bengali' => 'bn',
        'punjabi' => 'pa', 'urdu' => 'ur', 'tamil' => 'ta', 'telugu' => 'te', 'marathi' => 'mr',
        'gujarati' => 'gu', 'kannada' => 'kn', 'malayalam' => 'ml', 'turkish' => 'tr', 'polish' => 'pl',
        'swedish' => 'sv', 'thai' => 'th', 'vietnamese' => 'vi', 'indonesian' => 'id', 'hebrew' => 'he',
        'greek' => 'el',
    ];

    /**
     * ISO 639-2/3 codes to the 639-1 codes the `languages` table prefers.
     * MusicBrainz reports three-letter codes; everything else that reaches us is
     * either already two letters or an English language name.
     *
     * Only the languages the platform actually targets are listed. An unmapped
     * code is stored as-is rather than dropped: a slightly odd code is more
     * useful than no language at all, and the column is ten characters wide.
     *
     * @var array<string, string>
     */
    public const ISO_639_3_TO_1 = [
        'eng' => 'en', 'spa' => 'es', 'fra' => 'fr', 'fre' => 'fr', 'deu' => 'de', 'ger' => 'de',
        'ita' => 'it', 'por' => 'pt', 'nld' => 'nl', 'dut' => 'nl', 'rus' => 'ru', 'jpn' => 'ja',
        'kor' => 'ko', 'zho' => 'zh', 'chi' => 'zh', 'ara' => 'ar', 'hin' => 'hi', 'ben' => 'bn',
        'pan' => 'pa', 'urd' => 'ur', 'tam' => 'ta', 'tel' => 'te', 'mar' => 'mr', 'guj' => 'gu',
        'kan' => 'kn', 'mal' => 'ml', 'tur' => 'tr', 'pol' => 'pl', 'swe' => 'sv', 'tha' => 'th',
        'vie' => 'vi', 'ind' => 'id', 'heb' => 'he', 'ell' => 'el', 'gre' => 'el',
    ];

    /**
     * Whatever a provider or a listener called a language, as a 639-1 code.
     *
     * Returns null rather than a guess when the value is not a plausible code,
     * so a caller can tell "no language stated" from "some language we cannot
     * name" — the difference matters to dedup, which must not reject a match
     * over an unparseable string.
     */
    public static function toCode(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = mb_strtolower($value);

        $code = self::NAMES_TO_CODE[$normalized]
            ?? self::ISO_639_3_TO_1[$normalized]
            ?? $normalized;

        return preg_match('/^[a-z]{2,10}$/', $code) === 1 ? $code : null;
    }

    /** True when this word, on its own, names a language. */
    public static function isLanguageWord(string $word): bool
    {
        return isset(self::NAMES_TO_CODE[mb_strtolower(trim($word))]);
    }

    /** Reverse a code back to a display name where we know one. */
    public static function nameFor(string $code, string $fallback): string
    {
        $name = array_search($code, self::NAMES_TO_CODE, true);

        return is_string($name) ? Str::title($name) : Str::title($fallback);
    }
}
