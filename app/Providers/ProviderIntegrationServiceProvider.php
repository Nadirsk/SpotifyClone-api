<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Providers\ProviderAdapter;
use App\Services\Providers\Apple\AppleMusicAdapter;
use App\Services\Providers\Deezer\DeezerAdapter;
use App\Services\Providers\JioSaavn\JioSaavnAdapter;
use App\Services\Providers\LastFm\LastFmAdapter;
use App\Services\Providers\MusicBrainz\MusicBrainzAdapter;
use App\Services\Providers\ProviderManager;
use App\Services\Providers\Spotify\SpotifyAdapter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the provider integration layer (11_PROVIDER_INTEGRATION §13).
 *
 * Adding a provider is a two-line change here plus a config block: append the
 * adapter to ADAPTERS and add its credentials to config/providers.php. Nothing
 * that consumes providers needs to know it happened — the sync engine talks to
 * ProviderManager, and ProviderManager talks to the ProviderAdapter interface.
 *
 * Registering an adapter does not enable it. Construction is free of side
 * effects: no credential is read, no token is minted and no request is made
 * until something calls a method on a provider that ProviderManager has already
 * confirmed is enabled and configured.
 */
class ProviderIntegrationServiceProvider extends ServiceProvider
{
    /**
     * Every adapter the platform ships. Order here is irrelevant — the runtime
     * order comes from the `priority` column of the `providers` table.
     *
     * @var list<class-string<ProviderAdapter>>
     */
    private const ADAPTERS = [
        SpotifyAdapter::class,
        AppleMusicAdapter::class,
        DeezerAdapter::class,
        MusicBrainzAdapter::class,
        LastFmAdapter::class,
        JioSaavnAdapter::class,
    ];

    public function register(): void
    {
        foreach (self::ADAPTERS as $adapter) {
            /*
             | Singletons because each adapter carries per-provider state worth
             | reusing inside one request or job — a cached bearer token, the
             | throttle clock — and because building one is cheap but pointless
             | to repeat.
             */
            $this->app->singleton($adapter);
        }

        $this->app->singleton(ProviderManager::class, function (): ProviderManager {
            return new ProviderManager(
                logger: $this->app->make('log'),
                adapters: array_map(
                    fn (string $adapter): ProviderAdapter => $this->app->make($adapter),
                    self::ADAPTERS,
                ),
            );
        });
    }
}
