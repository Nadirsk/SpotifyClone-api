<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Song;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The development catalog: ~40 artists, their albums and songs.
 *
 * Rows are built in memory and written with chunked `insert()` rather than one
 * model save per song — a few thousand individual saves take minutes. That
 * bypasses Eloquent, so UUID primary keys and timestamps are generated here
 * explicitly (`Str::uuid7()` matches what `HasUuids` would have produced).
 *
 * Re-running is safe: an artist that already has songs is left alone rather
 * than given a second catalog.
 */
class CatalogSeeder extends Seeder
{
    private const CHUNK_SIZE = 500;

    /**
     * name, ISO country, language code, genre slugs, popularity tier.
     *
     * The list is hand-written rather than faked so the catalog spreads
     * sensibly across every seeded genre and language, and so the same names
     * come back on every reseed while the frontend is being built.
     *
     * @var list<array{string, string, string, list<string>, int}>
     */
    private const ARTISTS = [
        ['Neon Harbour', 'GB', 'en', ['indie', 'rock'], 84],
        ['Violet Static', 'US', 'en', ['rock', 'metal'], 71],
        ['Marlowe Fields', 'US', 'en', ['folk', 'country'], 58],
        ['The Paper Lanterns', 'GB', 'en', ['indie', 'pop'], 66],
        ['Cassidy Vaughn', 'US', 'en', ['pop', 'rnb'], 91],
        ['Northbound Ghosts', 'CA', 'en', ['rock', 'punk'], 49],
        ['Ember & Oak', 'US', 'en', ['folk', 'blues'], 43],
        ['Silver Meridian', 'AU', 'en', ['electronic', 'pop'], 77],
        ['Duke Ashcroft', 'GB', 'en', ['jazz', 'soul'], 52],
        ['Rosalind Wray', 'GB', 'en', ['classical', 'jazz'], 38],
        ['Static Bloom', 'US', 'en', ['electronic', 'indie'], 69],
        ['Hollow Cathedral', 'SE', 'en', ['metal', 'rock'], 63],
        ['Junia Vale', 'US', 'en', ['rnb', 'soul'], 80],
        ['The Brass Compass', 'IE', 'en', ['folk', 'blues'], 45],
        ['Bruna Alves', 'BR', 'pt', ['latin', 'pop'], 73],
        ['Elodie Marchand', 'FR', 'fr', ['pop', 'electronic'], 68],
        ['Rustbelt Choir', 'US', 'en', ['rock', 'blues'], 55],
        ['Lyra Pemberton', 'GB', 'en', ['classical', 'pop'], 41],
        ['Grit & Gospel', 'US', 'en', ['soul', 'blues'], 59],
        ['Fenwick Rowe', 'US', 'en', ['hip-hop', 'rnb'], 86],
        ['Ozone Kid', 'US', 'en', ['hip-hop', 'electronic'], 74],
        ['Bramble Row', 'GB', 'en', ['punk', 'indie'], 47],
        ['Delta Reeves', 'US', 'en', ['country', 'folk'], 62],
        ['Ivory Circuit', 'DE', 'de', ['electronic', 'indie'], 70],
        ['Aarav Mehta', 'IN', 'hi', ['bollywood', 'pop'], 88],
        ['Simran Kaul', 'IN', 'hi', ['bollywood', 'soul'], 79],
        ['Rukmini Das', 'IN', 'hi', ['bollywood', 'classical'], 64],
        ['Jaspreet Dhillon', 'IN', 'pa', ['punjabi-pop', 'hip-hop'], 90],
        ['Gurleen Bains', 'IN', 'pa', ['punjabi-pop', 'pop'], 72],
        ['Vetri Anand', 'IN', 'ta', ['pop', 'folk'], 76],
        ['Malini Raghavan', 'IN', 'ta', ['classical', 'folk'], 54],
        ['Chaitanya Reddy', 'IN', 'te', ['pop', 'folk'], 67],
        ['Neelima Varma', 'IN', 'ml', ['folk', 'classical'], 50],
        ['Los Faros Rojos', 'ES', 'es', ['latin', 'rock'], 75],
        ['Camila Ferrer', 'MX', 'es', ['latin', 'pop'], 83],
        ['Ritmo Salado', 'CO', 'es', ['latin', 'reggae'], 61],
        ['Hoshino Aya', 'JP', 'ja', ['j-pop', 'electronic'], 87],
        ['Kirisaki Blue', 'JP', 'ja', ['j-pop', 'rock'], 65],
        ['Seol', 'KR', 'ko', ['k-pop', 'pop'], 93],
        ['Han Jiwoo', 'KR', 'ko', ['k-pop', 'rnb'], 78],
    ];

    /** @var list<string> */
    private const ADJECTIVES = [
        'Golden', 'Midnight', 'Paper', 'Velvet', 'Broken', 'Neon', 'Quiet', 'Electric',
        'Distant', 'Crimson', 'Silver', 'Hollow', 'Restless', 'Wild', 'Sunken', 'Northern',
        'Endless', 'Bluer', 'Static', 'Gentle', 'Slow', 'Bright', 'Salt', 'Winter', 'Summer',
    ];

    /** @var list<string> */
    private const NOUNS = [
        'Hours', 'Cities', 'Machines', 'Letters', 'Rooms', 'Harbour', 'Signals', 'Bones',
        'Echoes', 'Mirrors', 'Gardens', 'Highways', 'Frequencies', 'Daylight', 'Shadows',
        'Anthems', 'Currents', 'Horizons', 'Tides', 'Windows', 'Circles', 'Lanterns',
        'Rivers', 'Ceilings', 'Weather',
    ];

    /** @var list<string> */
    private const VERBS = [
        'Hold', 'Chase', 'Follow', 'Break', 'Carry', 'Burn', 'Save', 'Leave',
        'Find', 'Waste', 'Answer', 'Outrun', 'Forget', 'Keep', 'Steal',
    ];

    /** @var list<string> */
    private const PHRASES = [
        "Don't Look Down", 'All I Wanted', 'One More Night', 'Nothing Left To Say',
        'Somewhere Loud', 'Back To The Start', 'Every Little Thing', 'Wait For Me',
        'Hold The Line', 'Long Way Home', 'Better Than This', 'Talk Me Down',
    ];

    /** @var list<string> */
    private const ISRC_COUNTRIES = ['US', 'GB', 'IN', 'FR', 'DE', 'JP', 'KR', 'ES', 'BR', 'SE'];

    /** @var array<string, true> */
    private array $usedSlugs = [];

    public function run(): void
    {
        $genres = $this->referenceMap(Genre::class, 'slug', GenreSeeder::class);
        $languages = $this->referenceMap(Language::class, 'code', LanguageSeeder::class);

        /*
         | Seeded so titles, durations and popularity come out the same on
         | every reseed. Only the UUIDs differ, which keeps screenshots and
         | manual test notes usable from one reseed to the next.
         */
        mt_srand(20_260_803);

        $now = Carbon::now();
        $artistsWithCatalog = Song::query()->select('artist_id')->distinct()->pluck('artist_id')->flip();

        /** @var list<array<string, mixed>> $albumRows */
        $albumRows = [];
        /** @var list<array<string, mixed>> $songRows */
        $songRows = [];
        $skipped = 0;

        foreach (self::ARTISTS as [$name, $country, $languageCode, $genreSlugs, $tier]) {
            $artist = Artist::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'bio' => sprintf(
                        '%s is a %s artist. Placeholder biography generated for development seed data.',
                        $name,
                        implode(' and ', $genreSlugs),
                    ),
                    'image' => 'https://picsum.photos/seed/'.Str::slug($name).'/400/400',
                    'country' => $country,
                    'popularity' => $tier,
                    'trending_score' => mt_rand(0, $tier * 40),
                ],
            );

            $artistId = (string) $artist->getKey();

            if ($artistsWithCatalog->has($artistId)) {
                $skipped++;

                continue;
            }

            $languageId = $languages[$languageCode] ?? null;
            /** @var array<string, true> $usedTitles */
            $usedTitles = [];

            foreach (range(1, mt_rand(1, 3)) as $ignored) {
                $releaseDate = $this->releaseDate(20);
                $trackCount = mt_rand(6, 12);
                $albumId = Str::uuid7()->toString();
                $albumPopularity = $this->jitter($tier, 12);
                $title = $this->uniqueTitle($usedTitles, fn (): string => $this->albumTitle());

                foreach (range(1, $trackCount) as $trackNumber) {
                    $songRows[] = $this->songRow(
                        artistId: $artistId,
                        albumId: $albumId,
                        genreId: $genres[$this->pick($genreSlugs)] ?? null,
                        languageId: $languageId,
                        title: $this->uniqueTitle($usedTitles, fn (): string => $this->songTitle()),
                        popularity: $this->jitter($albumPopularity, 15),
                        releaseDate: $releaseDate,
                        now: $now,
                        trackNumber: $trackNumber,
                    );
                }

                $albumRows[] = [
                    'id' => $albumId,
                    'artist_id' => $artistId,
                    'language_id' => $languageId,
                    'title' => $title,
                    'slug' => $this->uniqueSlug($title),
                    'cover_image' => 'https://picsum.photos/seed/'.Str::slug($title).'-'.substr($albumId, 0, 4).'/500/500',
                    'release_date' => $releaseDate,
                    // Denormalized: must equal the songs actually inserted above.
                    'total_tracks' => $trackCount,
                    'popularity' => $albumPopularity,
                    'trending_score' => mt_rand(0, $albumPopularity * 40),
                    'last_synced_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Singles: no album, and recent enough to populate "new releases".
            foreach (range(1, mt_rand(1, 3)) as $ignoredSingle) {
                $songRows[] = $this->songRow(
                    artistId: $artistId,
                    albumId: null,
                    genreId: $genres[$this->pick($genreSlugs)] ?? null,
                    languageId: $languageId,
                    title: $this->uniqueTitle($usedTitles, fn (): string => $this->songTitle()),
                    popularity: $this->jitter($tier, 18),
                    releaseDate: $this->releaseDate(3),
                    now: $now,
                );
            }
        }

        DB::transaction(function () use ($albumRows, $songRows): void {
            foreach (array_chunk($albumRows, self::CHUNK_SIZE) as $chunk) {
                DB::table('albums')->insert($chunk);
            }

            // Songs come second: album_id is a foreign key.
            foreach (array_chunk($songRows, self::CHUNK_SIZE) as $chunk) {
                DB::table('songs')->insert($chunk);
            }
        });

        $this->command?->info(sprintf(
            'Catalog seeded: %d artists (%d already had a catalog and were skipped), %d albums, %d songs.',
            count(self::ARTISTS),
            $skipped,
            count($albumRows),
            count($songRows),
        ));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  class-string<Seeder>  $seeder
     * @return array<string, string>
     */
    private function referenceMap(string $model, string $key, string $seeder): array
    {
        if (! $model::query()->exists()) {
            $this->call($seeder);
        }

        /** @var array<string, string> $map */
        $map = $model::query()->pluck('id', $key)->all();

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function songRow(
        string $artistId,
        ?string $albumId,
        ?string $genreId,
        ?string $languageId,
        string $title,
        int $popularity,
        string $releaseDate,
        Carbon $now,
        ?int $trackNumber = null,
    ): array {
        $slug = $this->uniqueSlug($title);

        return [
            'id' => Str::uuid7()->toString(),
            'artist_id' => $artistId,
            'album_id' => $albumId,
            'genre_id' => $genreId,
            'language_id' => $languageId,
            'title' => $title,
            'slug' => $slug,
            // Null for singles; album tracks get their 1..N running order.
            'track_number' => $albumId === null ? null : $trackNumber,
            'duration' => mt_rand(120, 360),
            'isrc' => $this->isrc(),
            'release_date' => $releaseDate,
            'popularity' => $popularity,
            'trending_score' => mt_rand(0, $popularity * 40),
            'play_count' => $popularity ** 2 * mt_rand(5, 50),
            'preview_url' => 'https://previews.example.test/'.$slug.'.mp3',
            'external_url' => 'https://listen.example.test/track/'.$slug,
            'last_synced_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function albumTitle(): string
    {
        return $this->pick(self::ADJECTIVES).' '.$this->pick(self::NOUNS);
    }

    private function songTitle(): string
    {
        return match (mt_rand(1, 5)) {
            1 => $this->pick(self::PHRASES),
            2 => $this->pick(self::NOUNS).' In The '.$this->pick(self::NOUNS),
            3 => $this->pick(self::VERBS).' The '.$this->pick(self::NOUNS),
            4 => $this->pick(self::NOUNS),
            default => $this->pick(self::ADJECTIVES).' '.$this->pick(self::NOUNS),
        };
    }

    /**
     * Keeps one artist from releasing the same title twice.
     *
     * @param  array<string, true>  $used
     * @param  callable(): string  $generator
     */
    private function uniqueTitle(array &$used, callable $generator): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $title = $generator();

            if (! isset($used[$title])) {
                $used[$title] = true;

                return $title;
            }
        }

        $title = $generator().' (Reprise)';
        $used[$title] = true;

        return $title;
    }

    /**
     * Slugs are not unique in the schema, but a duplicate would make
     * slug-based lookups ambiguous the moment one is added.
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $suffix = 2;

        while (isset($this->usedSlugs[$slug])) {
            $slug = $base.'-'.$suffix++;
        }

        $this->usedSlugs[$slug] = true;

        return $slug;
    }

    private function releaseDate(int $withinYears): string
    {
        return Carbon::now()
            ->subDays(mt_rand(0, $withinYears * 365))
            ->format('Y-m-d');
    }

    /** Popularity around a tier, clamped to the column's 0-100 range. */
    private function jitter(int $base, int $spread): int
    {
        return max(0, min(100, $base + mt_rand(-$spread, $spread)));
    }

    /**
     * Structurally plausible ISRC: 2-letter country, 3-character registrant,
     * 2-digit year of reference, 5-digit designation.
     */
    private function isrc(): string
    {
        $alphabet = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
        $registrant = $this->pick($alphabet).$this->pick($alphabet).$this->pick($alphabet);

        return sprintf(
            '%s%s%02d%05d',
            $this->pick(self::ISRC_COUNTRIES),
            $registrant,
            mt_rand(5, 26),
            mt_rand(1, 99_999),
        );
    }

    /**
     * @template TValue
     *
     * @param  list<TValue>  $items
     * @return TValue
     */
    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }
}
