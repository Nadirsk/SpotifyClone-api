<?php

declare(strict_types=1);

use App\Jobs\CleanupJob;
use App\Jobs\RefreshTrendingJob;
use App\Jobs\SyncAlbumsJob;
use App\Jobs\SyncArtistsJob;
use App\Jobs\SyncSongsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler (07_SYNC_ENGINE §9)
|--------------------------------------------------------------------------
|
| Every sync entry below is a no-op today: with no provider enabled and no
| credentials configured, ProviderManager::enabled() returns an empty list and
| the sync jobs return immediately (docs/DEFERRED.md §4). RefreshTrendingJob
| and CleanupJob touch no provider and run for real starting with the first
| deploy — trending needs listening history, not a provider.
|
| withoutOverlapping() guards every entry: an incremental sync or cleanup run
| that is still going when the next tick fires must not start a second copy
| fighting the first over the same rows.
|
| `php artisan schedule:work` must be running for any of this to fire — see
| README.md.
|
*/

Schedule::job(new RefreshTrendingJob)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('trending:refresh');

Schedule::job(new SyncSongsJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('sync:songs');

Schedule::job(new SyncArtistsJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('sync:artists');

Schedule::job(new SyncAlbumsJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('sync:albums');

Schedule::job(new CleanupJob)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->name('cleanup:daily');
