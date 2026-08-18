<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\LanguageNames;

/**
 * Pulls a language out of the words a listener typed, so it filters instead of
 * being matched against titles.
 *
 * The bug this fixes, in full: searching `M.S. Dhoni: The Untold Story in hindi`
 * returned nothing at all. `DatabaseSearchEngine::toBooleanTerm()` turns every
 * significant word into a **required** `+word*` clause, so `+hindi*` was ANDed
 * against `songs.title` — and no track on that soundtrack is called "hindi".
 * Verified against the live catalog: the same query minus that one word matches
 * the album, with it, zero rows.
 *
 * It then got worse rather than merely wrong. Zero strict hits is exactly what
 * `needsFuzzyFallback()` treats as "this must be a typo", so all six types fell
 * through to `fuzzyMatch()` — a 1,000-row scan each, scored with PHP
 * `levenshtein` — and `SearchService` separately fired an inline JioSaavn sync
 * for the same doomed phrase. One stray word turned a hit into a slow miss.
 *
 * ## Why only the trailing word
 *
 * A language name is a perfectly ordinary thing for a real title to contain, and
 * stripping one that belongs to the title is a worse bug than the one being
 * fixed. "English Vinglish" and "Hindi Medium" are both real films whose *first*
 * word names a language; neither ends with one. Trailing-only, with an optional
 * `in`/`language` connector in front, covers how people actually qualify a
 * search ("… in hindi", "kaun tujhe hindi") and leaves those titles alone.
 *
 * Something must also survive the strip: a bare "hindi" is a browse, not a
 * qualified search, and it should keep matching the language row by name rather
 * than becoming an empty query.
 */
final class LanguageTermExtractor
{
    /**
     * Words that only ever join a search to the language qualifying it, and so
     * are safe to drop along with it. Deliberately tiny — anything less
     * obviously a connector risks eating part of a real title.
     *
     * @var list<string>
     */
    private const CONNECTORS = ['in', 'language', 'lang'];

    /**
     * @return array{term: string, language: string|null} The term with the
     *                                                    qualifier removed, and the 639-1 code it named — or the input
     *                                                    unchanged with a null code when no qualifier was found.
     */
    public static function extract(string $term): array
    {
        $unchanged = ['term' => $term, 'language' => null];

        $words = preg_split('/\s+/u', trim($term)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));

        if (count($words) < 2) {
            // Nothing to qualify. See "Something must also survive" above.
            return $unchanged;
        }

        $last = (string) end($words);

        /*
         | Punctuation is trimmed for the *test* but the word is dropped whole:
         | "…story in hindi." should still filter, and re-attaching a stray
         | full stop to the shortened term would only hand FULLTEXT a token it
         | has no use for.
         */
        $candidate = trim($last, " \t\n\r\0\x0B.,;:!?()[]{}\"'");

        if (! LanguageNames::isLanguageWord($candidate)) {
            return $unchanged;
        }

        $code = LanguageNames::toCode($candidate);

        if ($code === null) {
            return $unchanged;
        }

        array_pop($words);

        if ($words !== [] && in_array(mb_strtolower((string) end($words)), self::CONNECTORS, true)) {
            array_pop($words);
        }

        if ($words === []) {
            // The whole term was "in hindi" — a language browse after all.
            return $unchanged;
        }

        return ['term' => implode(' ', $words), 'language' => $code];
    }
}
