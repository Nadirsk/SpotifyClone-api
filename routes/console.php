<?php

declare(strict_types=1);

use App\Jobs\CleanupJob;
use App\Jobs\CrawlFrontierJob;
use App\Jobs\DiscoverNewReleasesJob;
use App\Jobs\RefreshTrendingJob;
use App\Jobs\SyncAlbumsJob;
use App\Jobs\SyncArtistsJob;
use App\Jobs\SyncPlaylistsJob;
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
| Three distinct jobs, three cadences, and the distinction between them is the
| whole design:
|
| - **Discovery** (CrawlFrontierJob) finds records we do not have. It runs
|   often because the frontier is a work queue — each tick claims a bounded
|   batch, so throughput is a function of how frequently this fires and how
|   many queue workers are up, not of any one run going long.
|
| - **Freshness** (DiscoverNewReleasesJob) decides what is worth re-checking so
|   newly released music appears automatically. It makes no provider calls
|   itself; it re-opens cheap probes for the crawler to pick up, which keeps
|   all outbound traffic behind one rate limiter.
|
| - **Refresh** (Sync*Job) keeps records we already have accurate, oldest
|   first. Unchanged by this work except for the new playlist sibling.
|
| Nothing here fires unless `php artisan schedule:work` (or a system cron
| calling `schedule:run`) is running, AND a queue worker is consuming the
| `sync` queue — every entry below dispatches a job rather than doing work
| inline. See README.md.
|
| With no provider enabled, every sync and crawl entry is a no-op:
| ProviderManager::available() returns an empty list and the jobs return
| immediately. RefreshTrendingJob and CleanupJob touch no provider and run for
| real regardless.
|
| withoutOverlapping() guards every entry. It matters most for the crawl: a
| batch that is still walking a 458-page discography when the next tick fires
| must not start a second copy competing for the same leases.
|
*/

/*
 | Discovery. Every five minutes rather than hourly because this is the job
 | that builds the catalog in the first place, and on a cold install the
 | frontier holds hundreds of thousands of targets — an hourly batch of 25
 | would take years to drain. The batch size is the throttle here, not the
 | interval.
 */
Schedule::job(new CrawlFrontierJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('catalog:crawl');

/*
 | Freshness. Hourly is comfortably more often than needed to catch a release
 | on its release day, and the job is deliberately cheap — it queries our own
 | tables and re-opens frontier rows, making no provider calls of its own.
 */
Schedule::job(new DiscoverNewReleasesJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('catalog:new-releases');

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

/*
 | Playlists refresh more often than the other three entity types. A song's
 | metadata is essentially static once written, but an editorial playlist is
 | defined by its tracklist and is re-curated constantly — one that has not
 | been refreshed is wrong, not merely stale.
 */
Schedule::job(new SyncPlaylistsJob)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('sync:playlists');

Schedule::job(new CleanupJob)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->name('cleanup:daily');
