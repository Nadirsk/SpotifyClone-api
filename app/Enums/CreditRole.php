<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an artist is credited on a recording.
 *
 * The catalog schema gives `songs` a single `artist_id`, which is the name a
 * listing shows. It cannot express the other four or five people the provider
 * credits on the same track, and that omission is what made an artist's song
 * list *precise but narrow*: a composer's page returned only the tracks where
 * the adapter happened to pick them as the display artist, never the rest of
 * what they wrote. `song_credits` carries the full set, and this enum is the
 * vocabulary it is stored in.
 *
 * Normalized deliberately rather than storing the provider's own strings.
 * JioSaavn says `music` for a composer, `primary_artists` for a headline
 * credit and `starring` for a film actor; another provider will say something
 * else for the same three things. A role that reaches the API as a
 * provider-specific token would leak the provider's schema to clients, which
 * 11_PROVIDER_INTEGRATION forbids.
 */
enum CreditRole: string
{
    /** Headline credit — the provider's `artists.primary`. */
    case Primary = 'primary';

    /** A guest on someone else's track: `feat.` */
    case Featured = 'featured';

    /** Performed the vocal. */
    case Singer = 'singer';

    /** Wrote the music. Called a music director in film credits. */
    case Composer = 'composer';

    /** Wrote the words. */
    case Lyricist = 'lyricist';

    /**
     * Appeared in the film the song is from.
     *
     * Stored, because the provider publishes it and a soundtrack listing is
     * the poorer for dropping it, but deliberately NOT a discography credit —
     * see {@see isMusicCredit()}.
     */
    case Actor = 'actor';

    /**
     * Translate one provider's role token.
     *
     * Returns null for anything unrecognised rather than guessing: an
     * unmapped role silently filed as `Primary` would put strangers on an
     * artist's page, which is worse than a credit we did not store.
     */
    public static function fromProvider(?string $role): ?self
    {
        return match (mb_strtolower(trim((string) $role))) {
            'primary', 'primary_artists', 'primary_artist' => self::Primary,
            'featured', 'featured_artists', 'featured_artist' => self::Featured,
            'singer', 'singers', 'vocalist' => self::Singer,
            'music', 'composer', 'music_director', 'musicdirector' => self::Composer,
            'lyricist', 'lyricists', 'lyrics' => self::Lyricist,
            'starring', 'actor', 'actors', 'cast' => self::Actor,
            default => null,
        };
    }

    /**
     * Whether this credit means the artist contributed to the *music*.
     *
     * Everything except {@see self::Actor}. Varun Dhawan is credited
     * `starring` on "Apna Bana Le" because it is from his film; he did not
     * sing, write or compose it, and a listener opening his page expecting a
     * discography is being shown someone else's work. Composer and lyricist
     * credits, by contrast, are exactly what was missing — a music director's
     * page should list what they wrote.
     *
     * @return list<self>
     */
    public static function musicCredits(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $role): bool => $role->isMusicCredit(),
        ));
    }

    public function isMusicCredit(): bool
    {
        return $this !== self::Actor;
    }

    /** @return list<string> */
    public static function musicCreditValues(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::musicCredits());
    }

    /** How the role reads in a credits list. */
    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Artist',
            self::Featured => 'Featured artist',
            self::Singer => 'Singer',
            self::Composer => 'Composer',
            self::Lyricist => 'Lyricist',
            self::Actor => 'Starring',
        };
    }

    /**
     * Display order for a credits block, lowest first.
     *
     * Also the tiebreak that decides which of several credits is "the" one
     * when only one can be shown.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Primary => 0,
            self::Singer => 1,
            self::Featured => 2,
            self::Composer => 3,
            self::Lyricist => 4,
            self::Actor => 5,
        };
    }
}
