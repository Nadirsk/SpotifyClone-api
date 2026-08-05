<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\DTO\Providers\ProviderAlbumData;
use App\DTO\Providers\ProviderArtistData;
use App\DTO\Providers\ProviderSongData;
use App\Models\Album;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Language;
use Illuminate\Support\Str;

/**
 * Turns a provider DTO into the attribute arrays the local entities expect, and
 * resolves the lookup rows (genre, language, artist) those entities point at.
 *
 * This is the boundary the architecture insists on: a provider's own vocabulary
 * stops here. Nothing downstream sees "durationInMillis", "rank" or an ISO
 * 639-3 code, and no provider ID reaches the catalog tables — those live only
 * in the mapping tables (11_PROVIDER_INTEGRATION §5, §14).
 *
 * Null in, null out. A provider that does not know an artist's country must not
 * cause the local column to be overwritten with a guess; SyncService drops null
 * attributes before updating an existing row so a sparse provider cannot erase
 * what a richer one already supplied (07_SYNC_ENGINE §11).
 */
final class MetadataNormalizer
{
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
    private const ISO_639_3_TO_1 = [
        'eng' => 'en', 'spa' => 'es', 'fra' => 'fr', 'fre' => 'fr', 'deu' => 'de', 'ger' => 'de',
        'ita' => 'it', 'por' => 'pt', 'nld' => 'nl', 'dut' => 'nl', 'rus' => 'ru', 'jpn' => 'ja',
        'kor' => 'ko', 'zho' => 'zh', 'chi' => 'zh', 'ara' => 'ar', 'hin' => 'hi', 'ben' => 'bn',
        'pan' => 'pa', 'urd' => 'ur', 'tam' => 'ta', 'tel' => 'te', 'mar' => 'mr', 'guj' => 'gu',
        'kan' => 'kn', 'mal' => 'ml', 'tur' => 'tr', 'pol' => 'pl', 'swe' => 'sv', 'tha' => 'th',
        'vie' => 'vi', 'ind' => 'id', 'heb' => 'he', 'ell' => 'el', 'gre' => 'el',
    ];

    /**
     * English names to 639-1 codes, for providers that report a language by
     * name instead of a code.
     *
     * @var array<string, string>
     */
    private const NAMES_TO_CODE = [
        'english' => 'en', 'spanish' => 'es', 'french' => 'fr', 'german' => 'de', 'italian' => 'it',
        'portuguese' => 'pt', 'dutch' => 'nl', 'russian' => 'ru', 'japanese' => 'ja', 'korean' => 'ko',
        'chinese' => 'zh', 'mandarin' => 'zh', 'arabic' => 'ar', 'hindi' => 'hi', 'bengali' => 'bn',
        'punjabi' => 'pa', 'urdu' => 'ur', 'tamil' => 'ta', 'telugu' => 'te', 'marathi' => 'mr',
        'gujarati' => 'gu', 'kannada' => 'kn', 'malayalam' => 'ml', 'turkish' => 'tr', 'polish' => 'pl',
        'swedish' => 'sv', 'thai' => 'th', 'vietnamese' => 'vi', 'indonesian' => 'id', 'hebrew' => 'he',
        'greek' => 'el',
    ];

    /**
     * Column values for a `songs` row.
     *
     * @return array<string, mixed>
     */
    public function songAttributes(ProviderSongData $data, Artist $artist, ?Album $album): array
    {
        $genre = $this->resolveGenre($data->genre);
        $language = $this->resolveLanguage($data->language);

        return [
            'artist_id' => $artist->getKey(),
            'album_id' => $album?->getKey(),
            'genre_id' => $genre?->getKey(),
            'language_id' => $language?->getKey(),
            'title' => $this->trim($data->title),
            'slug' => Str::slug($data->title),
            /*
             | Passed through as null, NOT coerced to 0: the column is NOT NULL
             | with a migration-level ->default(0), and SyncService::writeEntity()
             | drops null attributes on both create and update, so an unknown
             | duration falls back to the DB default on first sync and never
             | overwrites a duration a richer provider already supplied.
             | Validation has already rejected records whose duration is a
             | genuine, unusable value.
             */
            'duration' => $data->duration,
            'isrc' => $this->isrc($data->isrc),
            'release_date' => $data->releaseDate,
            'popularity' => $this->clampPopularity($data->popularity),
            'preview_url' => $data->previewUrl,
            'external_url' => $data->externalUrl,
        ];
    }

    /**
     * Column values for an `artists` row.
     *
     * @return array<string, mixed>
     */
    public function artistAttributes(ProviderArtistData $data): array
    {
        return [
            'name' => $this->trim($data->name),
            'slug' => Str::slug($data->name),
            'bio' => $data->bio,
            'image' => $data->image,
            'country' => $this->country($data->country),
            'popularity' => $this->clampPopularity($data->popularity),
        ];
    }

    /**
     * Column values for an `albums` row.
     *
     * @return array<string, mixed>
     */
    public function albumAttributes(ProviderAlbumData $data, Artist $artist): array
    {
        $language = $this->resolveLanguage($data->language);

        return [
            'artist_id' => $artist->getKey(),
            'language_id' => $language?->getKey(),
            'title' => $this->trim($data->title),
            'slug' => Str::slug($data->title),
            'cover_image' => $data->image,
            'release_date' => $data->releaseDate,
            'total_tracks' => $data->totalTracks,
            'popularity' => $this->clampPopularity($data->popularity),
        ];
    }

    /**
     * Find or create the genre a provider named.
     *
     * Matching is by slug, which folds "Hip Hop", "hip-hop" and "Hip-Hop" into
     * one row — providers are wildly inconsistent about genre casing and
     * punctuation, and the slug column is the unique one.
     */
    public function resolveGenre(?string $name): ?Genre
    {
        $name = $this->trim($name);

        if ($name === null) {
            return null;
        }

        $slug = Str::slug($name);

        if ($slug === '') {
            return null;
        }

        return Genre::query()->firstOrCreate(
            ['slug' => $slug],
            // Title-case so the first provider to mention a genre does not fix
            // an all-lowercase display name for everyone.
            ['name' => Str::title($name)],
        );
    }

    /**
     * Find or create a language from whatever a provider supplied: a 639-1
     * code, a 639-2/3 code, or an English name.
     */
    public function resolveLanguage(?string $value): ?Language
    {
        $value = $this->trim($value);

        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower($value);
        $code = self::NAMES_TO_CODE[$normalized]
            ?? self::ISO_639_3_TO_1[$normalized]
            ?? $normalized;

        // Anything that is not a plausible code is not worth a lookup row.
        if (! preg_match('/^[a-z]{2,10}$/', $code)) {
            return null;
        }

        return Language::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $this->languageName($code, $value)],
        );
    }

    /**
     * Find or create an artist from a bare name.
     *
     * Songs and albums are NOT NULL on `artist_id`, so a provider that gives us
     * a track without a resolvable artist record still needs one. Matching is by
     * slug for the same reason as genres: providers punctuate names differently.
     */
    public function resolveArtist(string $name): ?Artist
    {
        $name = $this->trim($name);

        if ($name === null) {
            return null;
        }

        $slug = Str::slug($name);

        if ($slug === '') {
            // A name made entirely of characters Str::slug() strips — CJK-only
            // names, for instance. Fall back to a hash so the row is still
            // addressable and two identical names still collide.
            $slug = 'artist-'.substr(hash('xxh128', mb_strtolower($name)), 0, 12);
        }

        $artist = Artist::query()->firstOrNew(['slug' => $slug]);

        if (! $artist->exists) {
            $artist->fill(['name' => $name, 'slug' => $slug])->save();
        }

        return $artist;
    }

    /** Reverse the code back to a display name where we know one. */
    private function languageName(string $code, string $original): string
    {
        $name = array_search($code, self::NAMES_TO_CODE, true);

        return is_string($name) ? Str::title($name) : Str::title($original);
    }

    /**
     * ISRCs are twelve alphanumeric characters, frequently punctuated with
     * hyphens by the provider. Strip the punctuation so the dedupe lookup in
     * DeduplicationService compares like with like.
     */
    private function isrc(?string $isrc): ?string
    {
        if ($isrc === null) {
            return null;
        }

        $isrc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $isrc) ?? '');

        return strlen($isrc) === 12 ? $isrc : null;
    }

    private function country(?string $country): ?string
    {
        if ($country === null || strlen($country) !== 2) {
            return null;
        }

        return strtoupper($country);
    }

    /** The popularity columns are unsignedTinyInteger, so 0–100 or nothing. */
    private function clampPopularity(?int $popularity): ?int
    {
        return $popularity === null ? null : max(0, min(100, $popularity));
    }

    private function trim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }
}
