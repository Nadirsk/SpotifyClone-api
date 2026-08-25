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
     *
     * `PlanSeeder` runs unconditionally on every `db:seed` (including the
     * `--force` call the deploy pipeline makes after every migration) because
     * it is the only seeder here safe to run against production every time —
     * `updateOrCreate` on four fixed rows, never touching an admin-edited row
     * it wasn't asked about. The rest stay commented out: they either seed
     * one-time fixture/import data or would duplicate/overwrite real records.
     */
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
        ]);

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
