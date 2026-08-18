<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Order is dependency order, not preference: the catalog needs genres and
     * languages to reference, and the user library needs songs to point at.
     *
     * The three reference seeders are idempotent, so re-running only refreshes
     * them; the catalog and user seeders detect data they have already written
     * and skip or replace it rather than duplicating it.
     */
    public function run(): void
    {
        // $this->call([
        //     GenreSeeder::class,
        //     LanguageSeeder::class,
        //     ProviderSeeder::class,
        //     CatalogSeeder::class,
        //     UserSeeder::class,
        //     ConcertSeeder::class,
        // ]);
    }
}
