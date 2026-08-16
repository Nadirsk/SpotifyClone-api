<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AudioQuality;
use App\Models\Song;

/**
 * Turns a stored source URL into the variant for a requested quality tier.
 *
 * ## Why this can be done by string surgery
 *
 * `songs.preview_url` holds whatever `JioSaavnAdapter::bestVariant()` picked —
 * always the top of the provider's ladder, e.g.
 *
 *   https://aac.saavncdn.com/450/f467e05e…0550_320.mp4
 *
 * The provider publishes the same object at every rung under the same path with
 * only the bitrate suffix changed (`_12`, `_48`, `_96`, `_160`, `_320`), all
 * five of which were confirmed to resolve. So the whole ladder is derivable
 * from the one URL already synced, with no schema change, no re-sync, and no
 * extra outbound request on the hot path.
 *
 * That derivation is a *convention*, not a contract — the provider could change
 * its URL scheme tomorrow. Everything here therefore degrades rather than
 * fails: an unrecognised URL is returned untouched at whatever quality it
 * already is, which is exactly the behaviour the platform had before quality
 * tiers existed.
 *
 * This class never fetches anything. It maps a URL to a URL; `AudioProxy` is
 * what moves bytes, and only for downloads.
 */
final class AudioSourceResolver
{
    /**
     * The provider's ladder, per tier. `Lossless` is absent on purpose — no
     * variant serves it, and `AudioQuality::clampTo()` has already degraded a
     * lossless request to `VeryHigh` before it reaches here. Mapping it to 320
     * anyway would quietly claim a fidelity that was never delivered.
     *
     * @var array<string, int>
     */
    private const BITRATES = [
        AudioQuality::Low->value => 48,
        AudioQuality::Normal->value => 96,
        AudioQuality::High->value => 160,
        AudioQuality::VeryHigh->value => 320,
        AudioQuality::Lossless->value => 320,
    ];

    /** Matches the trailing `_<bitrate>` before the extension, and nothing else. */
    private const VARIANT_PATTERN = '/_(?:12|48|96|160|320)(\.[a-z0-9]{2,4})$/i';

    /**
     * The URL to play or download at this tier, plus the tier actually served.
     *
     * The second half matters: a caller that asked for `very_high` on a track
     * whose URL is not in the provider's recognised shape gets the original
     * URL back, and must not tell the listener they are hearing 320kbps. Read
     * `quality` off the result, never off the request.
     *
     * @return array{url: string, quality: AudioQuality, derived: bool}|null
     *         Null when the song has no source URL at all.
     */
    public function resolve(Song $song, AudioQuality $quality): ?array
    {
        $source = $song->preview_url;

        if ($source === null || trim($source) === '') {
            return null;
        }

        $bitrate = self::BITRATES[$quality->value] ?? null;

        if ($bitrate === null || ! preg_match(self::VARIANT_PATTERN, $source)) {
            return ['url' => $source, 'quality' => $this->qualityOf($source), 'derived' => false];
        }

        return [
            'url' => (string) preg_replace(self::VARIANT_PATTERN, "_{$bitrate}\$1", $source),
            'quality' => $quality,
            'derived' => true,
        ];
    }

    /** Whether a quality ladder can be derived for this song at all. */
    public function hasVariants(Song $song): bool
    {
        return $song->preview_url !== null
            && preg_match(self::VARIANT_PATTERN, $song->preview_url) === 1;
    }

    /**
     * The tier a URL already represents, for the degraded path above. Defaults
     * to `VeryHigh` because that is what the sync stores when the suffix is
     * unreadable — `bestVariant()` only ever picks the top rung.
     */
    private function qualityOf(string $url): AudioQuality
    {
        if (preg_match(self::VARIANT_PATTERN, $url, $matches) !== 1) {
            return AudioQuality::VeryHigh;
        }

        $bitrate = (int) ltrim(substr($matches[0], 0, -strlen($matches[1])), '_');

        foreach (self::BITRATES as $tier => $tierBitrate) {
            if ($tierBitrate === $bitrate) {
                return AudioQuality::from($tier);
            }
        }

        return AudioQuality::VeryHigh;
    }

    /**
     * A filesystem-safe `Artist - Title.ext` for the download's
     * `Content-Disposition`. Falls back to the song id when the title reduces
     * to nothing (a title of only punctuation, or of a script the sanitiser
     * strips), so the header is never emitted with an empty filename.
     */
    public function downloadFilename(Song $song, string $url): string
    {
        $extension = pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION);
        $extension = preg_match('/^[a-z0-9]{2,4}$/i', $extension) === 1 ? strtolower($extension) : 'mp4';

        $artist = $song->relationLoaded('artist') ? $song->artist?->name : null;
        $stem = $artist !== null ? "{$artist} - {$song->title}" : $song->title;

        /*
         | Zero-width characters first. The provider's artist names carry them
         | (a leading U+2060 on "Arijit Singh" is in the live catalog), and they
         | survive every other filter here while turning the ASCII fallback
         | filename into a run of underscores.
         */
        $safe = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', $stem) ?? $stem;

        // Then anything a filesystem or a Content-Disposition header would
        // choke on, collapsing the runs of separators that leaves behind.
        $safe = preg_replace('/[\x00-\x1F\/\\\\:*?"<>|]+/u', ' ', $safe) ?? '';
        $safe = trim((string) preg_replace('/\s+/u', ' ', $safe));

        return ($safe === '' ? $song->id : $safe).'.'.$extension;
    }
}
