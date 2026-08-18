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
use App\Services\Catalog\SoundtrackParser;
use App\Support\LanguageNames;
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
    public function __construct(
        private readonly SoundtrackParser $soundtracks,
    ) {}

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
            /*
             | Derived from the title, not supplied by any provider — see
             | SoundtrackParser. Null for the ~90% of the catalog that is not
             | film music, which SyncService::writeEntity() then drops, so a
             | re-sync never clears a film title it simply failed to re-derive.
             | The repair path for a genuinely-changed title is
             | `catalog:parse-soundtracks --fresh`.
             */
            'film_title' => $this->soundtracks->filmFrom($data->title),
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
            /*
             | Null for anything reached by search rather than by an album's
             | tracklist, which `SyncService::writeEntity()` then drops — so
             | the position an album fetch established is never cleared by a
             | later search that could not have known it.
             */
            'track_number' => $data->trackNumber,
            'isrc' => $this->isrc($data->isrc),
            'release_date' => $data->releaseDate,
            'popularity' => $this->clampPopularity($data->popularity),
            /*
             | The provider's own counter, kept alongside `popularity`, which is
             | this same number rescaled to 0-100. Both earn their place:
             | popularity sorts a listing cheaply but saturates at the head of
             | the catalog, while this is the figure a track page shows.
             |
             | Nothing to do with the app's own play counting — `trending_score`
             | is computed from local listening history and never from this.
             */
            'play_count' => $data->playCount,
            'preview_url' => $data->previewUrl,
            'external_url' => $data->externalUrl,
            'label' => $data->label,
            'copyright' => $data->copyright,
            'is_explicit' => $data->explicit,
            'has_lyrics' => $data->hasLyrics,
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
            // The unscaled figure behind `popularity`; see songAttributes()'s
            // note on play_count for why both columns earn their place.
            'follower_count' => $data->followerCount,
            'is_verified' => $data->isVerified,
            'dominant_type' => $data->dominantType,
            'dominant_language' => $data->dominantLanguage,
            'birth_date' => $data->birthDate,
            'facebook_url' => $data->facebookUrl,
            'twitter_url' => $data->twitterUrl,
            'wiki_url' => $data->wikiUrl,
            /*
             | Written only when the provider actually listed some. An empty
             | array is not null, so it would survive writeEntity()'s filter and
             | overwrite a populated column on any sync from a thinner source —
             | exactly the blanking that filter exists to prevent.
             */
            'available_languages' => $data->availableLanguages === [] ? null : $data->availableLanguages,
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
            // An album title rarely carries the credit its tracks do;
            // `catalog:parse-soundtracks` fills the rest in from the tracklist.
            'film_title' => $this->soundtracks->filmFrom($data->title),
            'slug' => Str::slug($data->title),
            'cover_image' => $data->image,
            'release_date' => $data->releaseDate,
            'total_tracks' => $data->totalTracks,
            'popularity' => $this->clampPopularity($data->popularity),
            'description' => $data->description,
            'is_explicit' => $data->explicit,
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
        $code = LanguageNames::toCode($normalized);

        // Anything that is not a plausible code is not worth a lookup row.
        if ($code === null) {
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
        return LanguageNames::nameFor($code, $original);
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
