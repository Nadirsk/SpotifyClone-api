<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A key-gated operational status page - GET /diagnostics?key=...
 *
 * Exists for exactly one situation: nobody working on this app has server
 * (SSH/host) access, so there is no `supervisorctl status` or `crontab -l`
 * to run to see whether the scheduler, the queue worker or Reverb are
 * actually alive in production. Everything shown here is answered from
 * inside the app instead:
 *
 * - The scheduler and queue rows read a heartbeat timestamp two other
 *   places in the app write - `routes/console.php`'s `diagnostics:heartbeat`
 *   entry (every minute) and `AppServiceProvider::configureQueueHeartbeat()`
 *   (every job processed). A stale or missing heartbeat is the same signal
 *   `supervisorctl status` would give, arrived at without a shell.
 *
 * - Reverb is deliberately NOT checked here in PHP. A server-side check would
 *   only prove this app can reach its own Reverb process, which is not the
 *   question - the question is whether a real visitor's browser, from
 *   outside, can complete the WebSocket handshake through whatever Nginx/TLS
 *   sits in front of it. So the page ships the public REVERB_* values to the
 *   browser and lets client-side JS make that exact connection itself.
 *
 * `hash_equals()` and a 404 (not 403) on a wrong/missing key: this matches
 * every other "unauthenticated stranger" answer in this app, and does not
 * confirm the route exists to someone guessing at it. An unset key always
 * 404s too, rather than accidentally becoming public because nobody set one.
 */
final class DiagnosticsController extends Controller
{
    /** Above this, the scheduler heartbeat (every 60s) reads as stale. */
    private const SCHEDULER_STALE_AFTER_SECONDS = 180;

    public function show(Request $request): View
    {
        $key = (string) config('diagnostics.key');
        $given = (string) $request->query('key', '');

        if ($key === '' || ! hash_equals($key, $given)) {
            abort(404);
        }

        $now = Carbon::now();
        $schedulerHeartbeat = Cache::get('diagnostics:scheduler_heartbeat');
        $queueHeartbeat = Cache::get('diagnostics:queue_heartbeat');

        $schedulerAgeSeconds = $this->ageInSeconds($schedulerHeartbeat, $now);

        return view('diagnostics', [
            'now' => $now,
            'schedulerAgeSeconds' => $schedulerAgeSeconds,
            'schedulerStale' => $schedulerAgeSeconds === null || $schedulerAgeSeconds > self::SCHEDULER_STALE_AFTER_SECONDS,
            'queueAgeSeconds' => $this->ageInSeconds($queueHeartbeat, $now),
            'failedJobs' => (int) DB::table('failed_jobs')->count(),
            'processes' => $this->runningArtisanProcesses(),
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ],
        ]);
    }

    private function ageInSeconds(mixed $heartbeat, Carbon $now): ?int
    {
        if (! $heartbeat instanceof Carbon) {
            return null;
        }

        // Carbon's diffInSeconds returns a float (it supports sub-second
        // precision generally); this call site only ever wants whole seconds.
        return (int) round($heartbeat->diffInSeconds($now, true));
    }

    /**
     * Live `ps` snapshot of every artisan process on this box, e.g. the exact
     * `--queue=` flags `queue:work` was started with, or whether
     * `schedule:work` / `reverb:start` are running at all - the things the
     * heartbeats above can't distinguish (a heartbeat only proves *some*
     * queue/scheduler process is alive, not which one or with what flags).
     *
     * Null means the check itself could not run (shell_exec disabled, or this
     * isn't a shell that has `ps` - e.g. local Windows dev). Empty array means
     * it ran cleanly and found nothing.
     */
    private function runningArtisanProcesses(): ?array
    {
        if (! function_exists('shell_exec') || str_contains((string) ini_get('disable_functions'), 'shell_exec')) {
            return null;
        }

        $output = @shell_exec('ps -eo pid,args 2>/dev/null | grep "artisan" | grep -v grep');

        if ($output === null) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }
}
