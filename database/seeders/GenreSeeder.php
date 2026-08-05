<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;

/**
 * Reference data. Idempotent on `slug`, which is the key the catalog filters
 * use (`/songs?genre=hip-hop`), so slugs are written out rather than derived —
 * `Str::slug('R&B')` would produce `rb`, which nobody would ever type.
 */
class GenreSeeder extends Seeder
{
    /** @var list<array{string, string}> */
    private const GENRES = [
        ['Pop', 'pop'],
        ['Rock', 'rock'],
        ['Hip-Hop', 'hip-hop'],
        ['R&B', 'rnb'],
        ['Electronic', 'electronic'],
        ['Jazz', 'jazz'],
        ['Classical', 'classical'],
        ['Country', 'country'],
        ['Metal', 'metal'],
        ['Indie', 'indie'],
        ['Folk', 'folk'],
        ['Reggae', 'reggae'],
        ['Blues', 'blues'],
        ['Punk', 'punk'],
        ['Soul', 'soul'],
        ['Bollywood', 'bollywood'],
        ['Punjabi Pop', 'punjabi-pop'],
        ['K-Pop', 'k-pop'],
        ['J-Pop', 'j-pop'],
        ['Latin', 'latin'],
    ];

    public function run(): void
    {
        foreach (self::GENRES as [$name, $slug]) {
            Genre::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
        }

        $this->command?->info(sprintf('Genres seeded: %d.', count(self::GENRES)));
    }
}
