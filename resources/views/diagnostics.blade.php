<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>System Diagnostics</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #121212;
            color: #fff;
            padding: 40px 24px;
            margin: 0;
        }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .subtitle { color: #999; font-size: 13px; margin: 0 0 28px; }
        table { border-collapse: collapse; width: 100%; max-width: 720px; }
        td { padding: 14px 16px; border-bottom: 1px solid #2a2a2a; font-size: 14px; vertical-align: top; }
        td:first-child { color: #ccc; width: 200px; white-space: nowrap; }
        .ok { color: #1db954; font-weight: 700; }
        .warn { color: #ffa42b; font-weight: 700; }
        .fail { color: #f15e6c; font-weight: 700; }
        .pending { color: #999; }
        .detail { color: #777; font-size: 12px; margin-top: 6px; line-height: 1.5; }
        .refresh {
            display: inline-block; margin-top: 24px; color: #999; font-size: 13px;
            text-decoration: none; border: 1px solid #333; padding: 8px 16px; border-radius: 20px;
        }
        .refresh:hover { color: #fff; border-color: #666; }
    </style>
</head>
<body>
    <h1>System Diagnostics</h1>
    <p class="subtitle">Generated {{ $now->toDayDateTimeString() }} ({{ $now->timezoneName }})</p>

    <table>
        <tr>
            <td>Scheduler (cron)</td>
            <td>
                @if ($schedulerAgeSeconds === null)
                    <span class="fail">Never ticked</span>
                    <div class="detail">
                        No heartbeat recorded. Either <code>schedule:run</code> has never fired via
                        cron, or the cache was cleared since the last tick. Expected every 60s.
                    </div>
                @elseif ($schedulerStale)
                    <span class="fail">Stale &mdash; last tick {{ $schedulerAgeSeconds }}s ago</span>
                    <div class="detail">
                        Expected every 60s. The system cron entry calling
                        <code>php artisan schedule:run</code> is most likely not running.
                    </div>
                @else
                    <span class="ok">Ticking &mdash; last tick {{ $schedulerAgeSeconds }}s ago</span>
                @endif
            </td>
        </tr>
        <tr>
            <td>Queue worker</td>
            <td>
                @if ($queueAgeSeconds === null)
                    <span class="pending">No job processed yet</span>
                    <div class="detail">
                        Not necessarily down &mdash; this stays blank until a job actually runs, and
                        the crawl/sync jobs fire every few minutes at the earliest. For an immediate,
                        live check: register a brand-new account with a real email address and see
                        whether the welcome email arrives &mdash; that notification is only ever sent
                        through the queue.
                    </div>
                @else
                    <span class="ok">Last job finished {{ $queueAgeSeconds }}s ago</span>
                @endif
            </td>
        </tr>
        <tr>
            <td>Failed jobs</td>
            <td>
                @if ($failedJobs === 0)
                    <span class="ok">0</span>
                @else
                    <span class="warn">{{ $failedJobs }}</span>
                    <div class="detail">Run <code>php artisan queue:failed</code> on the server for details.</div>
                @endif
            </td>
        </tr>
        <tr>
            <td>WebSocket (Reverb)</td>
            <td id="reverb-status"><span class="pending">Checking&hellip;</span></td>
        </tr>
    </table>

    <a class="refresh" href="{{ url()->full() }}">&#8635; Refresh</a>

    <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
    <script>
        (function () {
            var statusEl = document.getElementById('reverb-status');
            var key = @json($reverb['key']);
            var host = @json($reverb['host']);
            var port = @json($reverb['port']);
            var scheme = @json($reverb['scheme']);

            if (!key || !host) {
                statusEl.innerHTML = '<span class="fail">Not configured</span>'
                    + '<div class="detail">REVERB_APP_KEY / REVERB_HOST are missing from .env.</div>';
                return;
            }

            var secure = scheme === 'https';
            var start = Date.now();
            var settled = false;

            // Verbose so the raw close code/reason for a failed or timed-out
            // handshake lands in the browser console, not just the page's
            // one-line summary - that raw detail is what actually tells apart
            // "Reverb is down" from "Nginx never proxies the upgrade" below.
            Pusher.logToConsole = true;

            var timeout = setTimeout(function () {
                if (settled) return;
                settled = true;
                console.error('[diagnostics] Reverb handshake timed out after 8s', {
                    url: scheme + '://' + host + ':' + port,
                    connectionState: pusher.connection.state,
                });
                statusEl.innerHTML = '<span class="fail">Timed out</span>'
                    + '<div class="detail">No response within 8s from '
                    + scheme + '://' + host + ':' + port + '. Check that `php artisan reverb:start` '
                    + 'is running and that Nginx is proxying the WebSocket upgrade to it.</div>';
                try { pusher.disconnect(); } catch (e) {}
            }, 8000);

            var pusher = new Pusher(key, {
                // Required by pusher-js's constructor even though Reverb has no
                // concept of clusters - laravel-echo's Reverb connector supplies
                // this internally, but this page talks to the raw client directly.
                cluster: '',
                wsHost: host,
                wsPort: port,
                wssPort: port,
                forceTLS: secure,
                enabledTransports: secure ? ['wss'] : ['ws'],
                disableStats: true,
            });

            pusher.connection.bind('connected', function () {
                if (settled) return;
                settled = true;
                clearTimeout(timeout);
                var ms = Date.now() - start;
                statusEl.innerHTML = '<span class="ok">Connected (' + ms + 'ms)</span>'
                    + '<div class="detail">Handshake completed against '
                    + scheme + '://' + host + ':' + port + ' from this browser, over the public internet '
                    + '&mdash; the same path a real visitor takes.</div>';
                pusher.disconnect();
            });

            pusher.connection.bind('error', function (err) {
                if (settled) return;
                settled = true;
                clearTimeout(timeout);
                // The page only ever shows the extracted message; the full object
                // (close code, type - e.g. WebSocketError vs PusherError) only goes
                // to the console, since that's what actually distinguishes a TLS/DNS
                // failure from Nginx accepting the socket and Reverb refusing it.
                console.error('[diagnostics] Reverb connection error', err);
                var message = (err && err.error && err.error.data && err.error.data.message)
                    ? err.error.data.message
                    : ('Could not reach ' + scheme + '://' + host + ':' + port);
                statusEl.innerHTML = '<span class="fail">Connection failed</span>'
                    + '<div class="detail">' + message + '</div>';
            });
        })();
    </script>
</body>
</html>
