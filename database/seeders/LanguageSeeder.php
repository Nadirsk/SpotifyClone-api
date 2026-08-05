<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

/**
 * Reference data. The first nine are the languages search must support
 * (06_SEARCH_ARCHITECTURE §7); the rest round out the catalog. Idempotent on
 * the ISO 639-1 `code`, which is what `/songs?language=hi` matches on.
 */
class LanguageSeeder extends Seeder
{
    /** @var list<array{string, string}> */
    private const LANGUAGES = [
        ['English', 'en'],
        ['Hindi', 'hi'],
        ['Punjabi', 'pa'],
        ['Tamil', 'ta'],
        ['Telugu', 'te'],
        ['Malayalam', 'ml'],
        ['Spanish', 'es'],
        ['Japanese', 'ja'],
        ['Korean', 'ko'],
        ['French', 'fr'],
        ['German', 'de'],
        ['Portuguese', 'pt'],
    ];

    public function run(): void
    {
        foreach (self::LANGUAGES as [$name, $code]) {
            Language::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name],
            );
        }

        $this->command?->info(sprintf('Languages seeded: %d.', count(self::LANGUAGES)));
    }
}
