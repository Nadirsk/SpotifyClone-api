<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Blend;
use App\Models\ListeningRoom;
use App\Models\Playlist;
use App\Policies\BlendPolicy;
use App\Policies\ListeningRoomPolicy;
use App\Policies\PlaylistPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Authorization wiring.
 *
 * Policies are mapped here rather than with a `#[UsePolicy]` attribute on the
 * model, so the models stay free of application-layer references and every
 * mapping is visible in one place.
 *
 * Register this provider in `bootstrap/providers.php`.
 */
class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Playlist::class => PlaylistPolicy::class,
        ListeningRoom::class => ListeningRoomPolicy::class,
        Blend::class => BlendPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
