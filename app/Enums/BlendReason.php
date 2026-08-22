<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a song made it into a Blend — the transparent half of "don't copy
 * Spotify's algorithm, but do explain your own" (12_SCOPE_OF_WORK has no
 * source; built to spec). Stored per row in `blend_songs` and surfaced on
 * `BlendTrackResource` so a client *could* group or label the tracklist by it,
 * even though the reference UI (a flat, Spotify-style list) does not.
 */
enum BlendReason: string
{
    /** Favorited or recently played by two or more members. */
    case Shared = 'shared';

    /** Strongly tied to exactly one member's own favorites, history, or top artists/genres. */
    case Taste = 'taste';

    /** Neither of the above — a related-song candidate pulled in to fill out the Blend. */
    case Discover = 'discover';
}
