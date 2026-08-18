<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Recovers the film a recording belongs to from its title.
 *
 * The catalog provider has no soundtrack flag, but it does have a naming
 * convention it applies consistently to film music:
 *
 *   Gehra Hua (From "Dhurandhar")
 *   Tum Hi Ho (From "Aashiqui 2")
 *   Bandeya [From "Dil Juunglee"]
 *
 * Roughly one song in ten in the synced Hindi catalog carries it. Parsing it
 * into `songs.film_title` / `albums.film_title` (see that migration) is what
 * makes the Soundtracks hub a grouped index rather than a `LIKE` scan.
 *
 * Deliberately conservative: it matches the convention exactly and returns null
 * for anything else. A looser heuristic — treating any parenthetical as a film —
 * would fill the hub with "(Lofi Mix)" and "(Live)" and be worse than no
 * feature. Under-matching leaves a real soundtrack out of the hub; over-matching
 * invents films that do not exist, so the failure modes are not symmetrical.
 */
final class SoundtrackParser
{
    /**
     * `(From "X")`, `[From "X"]`, and the curly-quote variants the provider
     * mixes in. The `From` keyword is required — a bare quoted parenthetical is
     * far more likely to be a remix credit than a film.
     */
    private const PATTERN = '/[\(\[]\s*from\s*["“”\'‘’]\s*(?<film>.+?)\s*["“”\'‘’]\s*[\)\]]/iu';

    /** The film name, or null when the title does not use the convention. */
    public function filmFrom(?string $title): ?string
    {
        if ($title === null || trim($title) === '') {
            return null;
        }

        if (preg_match(self::PATTERN, $title, $matches) !== 1) {
            return null;
        }

        $film = trim($matches['film']);

        /*
         | Guard against the degenerate matches the pattern would otherwise
         | accept: an empty capture, and one so long it is clearly a sentence
         | rather than a title. 120 is well past the longest real film name in
         | the catalog and well short of a description.
         */
        return $film === '' || mb_strlen($film) > 120 ? null : $film;
    }

    /** Whether this title advertises itself as film music. */
    public function isSoundtrack(?string $title): bool
    {
        return $this->filmFrom($title) !== null;
    }

    /**
     * The title with the film credit removed — `Gehra Hua (From "Dhurandhar")`
     * becomes `Gehra Hua`.
     *
     * For display inside the hub, where every row is already under the film's
     * heading and repeating it on each line is noise. The stored title is never
     * rewritten; this is a view concern.
     */
    public function withoutFilmCredit(string $title): string
    {
        $stripped = preg_replace(self::PATTERN, '', $title) ?? $title;
        $stripped = trim((string) preg_replace('/\s{2,}/u', ' ', $stripped));

        return $stripped === '' ? $title : $stripped;
    }
}
