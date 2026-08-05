<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Provider;
use Illuminate\Database\Seeder;

/**
 * Reference data. Idempotent on `api_name`, which is the adapter key.
 *
 * `enabled` is re-read from the environment on every run so the environment
 * stays the single source of truth: turning a provider off in `.env` and
 * re-seeding disables it, rather than leaving a stale `true` in the table.
 * Priority follows 11_PROVIDER_INTEGRATION §10 — lower wins when merging.
 */
class ProviderSeeder extends Seeder
{
    /** @var list<array{string, string, int, string}> */
    private const PROVIDERS = [
        ['Spotify', 'spotify', 1, 'SPOTIFY_ENABLED'],
        ['Apple Music', 'apple_music', 2, 'APPLE_MUSIC_ENABLED'],
        ['Deezer', 'deezer', 3, 'DEEZER_ENABLED'],
        ['MusicBrainz', 'musicbrainz', 4, 'MUSICBRAINZ_ENABLED'],
        ['Last.fm', 'lastfm', 5, 'LASTFM_ENABLED'],
        ['JioSaavn', 'jiosaavn', 6, 'JIOSAAVN_ENABLED'],
    ];

    public function run(): void
    {
        foreach (self::PROVIDERS as [$name, $apiName, $priority, $envKey]) {
            Provider::query()->updateOrCreate(
                ['api_name' => $apiName],
                [
                    'name' => $name,
                    'enabled' => self::enabledFromEnv($envKey),
                    'priority' => $priority,
                ],
            );
        }

        $this->command?->info(sprintf('Providers seeded: %d.', count(self::PROVIDERS)));
    }

    /**
     * `env()` is read directly because provider flags have no config file yet;
     * the string forms ("false", "0", "off") must all resolve to false, which
     * a plain cast would get wrong.
     */
    private static function enabledFromEnv(string $key): bool
    {
        return filter_var(env($key, false), FILTER_VALIDATE_BOOLEAN);
    }
}
