<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\Providers\ProviderAdapter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;

/**
 * Everything the five adapters would otherwise each reimplement: timeouts,
 * retry with exponential backoff, `Retry-After`-aware 429 handling, a circuit
 * breaker, client-side rate limiting and secret-scrubbing failure logs
 * (11_PROVIDER_INTEGRATION §8 and §9, 07_SYNC_ENGINE §10).
 *
 * A subclass supplies its key, its credential check and its response mapping,
 * and gets a network layer that cannot hammer a dead provider.
 *
 * Failure policy: transport problems never escape this class. `send()` logs and
 * returns null, so a subclass returns `[]`/`null` and the caller carries on.
 * Only a misconfiguration — enabled but no usable credential — throws, and only
 * from `authenticate()`, because that is an operator error worth surfacing.
 */
abstract class AbstractProviderAdapter implements ProviderAdapter
{
    /**
     * Namespace for the adapter's operational cache entries (tokens, breaker
     * state, throttle clock).
     *
     * These deliberately bypass App\Services\Cache\CacheService: that service
     * exists to give *domain* reads a config-driven TTL per bucket, whereas
     * these are short-lived operational flags whose lifetimes come from
     * config/providers.php. Routing them through CacheService would mean
     * inventing fake buckets in config/music.php.
     */
    private const CACHE_NAMESPACE = 'providers';

    /**
     * Query-string and context keys whose values must never reach the log.
     * Matched case-insensitively as substrings, so `client_secret`,
     * `api_key` and `Authorization` are all caught.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'key', 'secret', 'token', 'password', 'authorization', 'signature', 'credential',
    ];

    public function __construct(
        protected readonly CacheRepository $cache,
        protected readonly LoggerInterface $logger,
    ) {}

    /** True when the provider is switched on AND every credential it needs is present. */
    public function isEnabled(): bool
    {
        return $this->setting('enabled') === true && $this->hasCredentials();
    }

    /** Public APIs need nothing; token-based adapters override this. */
    public function authenticate(): void
    {
        // No credential exchange required by default.
    }

    /**
     * Whether every secret this adapter needs is actually present. Keeps a
     * half-configured provider (flag flipped, key still blank) from making a
     * call that can only 401.
     */
    abstract protected function hasCredentials(): bool;

    /**
     * Headers sent with every request — auth headers included. Never logged.
     *
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [];
    }

    /**
     * Resolve a config value for this provider, falling back to the shared
     * `providers.defaults` block.
     */
    protected function setting(string $path, mixed $default = null): mixed
    {
        return config(
            "providers.{$this->key()}.{$path}",
            config("providers.defaults.{$path}", $default),
        );
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->setting('base_url'), '/');
    }

    /**
     * GET a provider endpoint and return the decoded body, or null on any
     * failure the caller cannot do anything about.
     *
     * @param  array<string, scalar|null>  $query
     * @param  array<string, string>  $headers
     * @return array<array-key, mixed>|null
     */
    protected function get(string $url, array $query = [], array $headers = []): ?array
    {
        return $this->send('GET', $url, $query, [], $headers);
    }

    /**
     * POST a form-encoded body. Used for token exchanges, so the payload is
     * assumed to hold secrets and is never logged.
     *
     * @param  array<string, scalar|null>  $form
     * @param  array<string, string>  $headers
     * @return array<array-key, mixed>|null
     */
    protected function post(string $url, array $form = [], array $headers = []): ?array
    {
        return $this->send('POST', $url, [], $form, $headers);
    }

    /**
     * The retry loop.
     *
     * Attempt N waits base_delay_ms * 2^(N-1) with jitter, capped at
     * max_delay_ms, for at most max_attempts attempts (07_SYNC_ENGINE §10).
     * Jitter matters because sync jobs for several providers start together;
     * without it their retries would stay in lockstep and arrive as a burst.
     *
     * @param  array<string, scalar|null>  $query
     * @param  array<string, scalar|null>  $form
     * @param  array<string, string>  $headers
     * @return array<array-key, mixed>|null
     */
    private function send(string $method, string $url, array $query, array $form, array $headers): ?array
    {
        // Belt and braces: no adapter should reach here while disabled, but a
        // misuse must not turn into an outbound call.
        if (! $this->isEnabled()) {
            return null;
        }

        if ($this->circuitIsOpen()) {
            $this->logger->debug('Provider request suppressed by open circuit', [
                'provider' => $this->key(),
                'url' => $this->safeUrl($url),
            ]);

            return null;
        }

        $maxAttempts = max(1, (int) $this->setting('retry.max_attempts', 5));
        $baseDelay = max(1, (int) $this->setting('retry.base_delay_ms', 500));
        $maxDelay = max($baseDelay, (int) $this->setting('retry.max_delay_ms', 60_000));
        $allHeaders = array_merge($this->defaultHeaders(), $headers);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->throttle();

            try {
                $request = Http::withHeaders($allHeaders)
                    ->timeout((int) $this->setting('timeout', 10))
                    ->connectTimeout((int) $this->setting('connect_timeout', 5))
                    ->acceptJson();

                $response = $method === 'POST'
                    ? $request->asForm()->post($url, $form)
                    : $request->get($url, $query);
            } catch (ConnectionException $exception) {
                // DNS failure, refused connection, timeout: retryable.
                $this->logFailure('connection', $url, [
                    'attempt' => $attempt,
                    'message' => $exception->getMessage(),
                ]);

                if ($attempt >= $maxAttempts) {
                    $this->recordFailure();

                    return null;
                }

                Sleep::for($this->backoffMs($attempt, $baseDelay, $maxDelay))->milliseconds();

                continue;
            }

            if ($response->status() === 429) {
                /*
                 | The provider has told us exactly when it will serve us again.
                 | Honour that instead of our own curve — backing off less than
                 | asked risks a ban, backing off more wastes the window. The
                 | cap still applies so a hostile `Retry-After: 86400` cannot
                 | park a worker for a day.
                 */
                $wait = min($this->retryAfterMs($response) ?? $this->backoffMs($attempt, $baseDelay, $maxDelay), $maxDelay);

                $this->logFailure('rate_limited', $url, [
                    'attempt' => $attempt,
                    'retry_after_ms' => $wait,
                ]);

                if ($attempt >= $maxAttempts) {
                    // Sustained 429s are exactly what the breaker is for.
                    $this->recordFailure();

                    return null;
                }

                Sleep::for($wait)->milliseconds();

                continue;
            }

            if ($response->serverError()) {
                $this->logFailure('server_error', $url, [
                    'attempt' => $attempt,
                    'status' => $response->status(),
                ]);

                if ($attempt >= $maxAttempts) {
                    $this->recordFailure();

                    return null;
                }

                Sleep::for($this->backoffMs($attempt, $baseDelay, $maxDelay))->milliseconds();

                continue;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                /*
                 | Credentials were rejected. Drop any cached token so the next
                 | run re-authenticates rather than replaying a dead one, and
                 | count it against the breaker: retrying a rejected credential
                 | in-loop only burns quota.
                 */
                $this->forgetToken();
                $this->logFailure('unauthorized', $url, ['status' => $response->status()]);
                $this->recordFailure();

                return null;
            }

            if ($response->clientError()) {
                /*
                 | 404 and friends are a real answer about a specific record —
                 | our request was wrong, the provider is healthy. Deliberately
                 | not counted against the breaker.
                 */
                $this->logger->debug('Provider returned no record', [
                    'provider' => $this->key(),
                    'status' => $response->status(),
                    'url' => $this->safeUrl($url),
                ]);

                return null;
            }

            $this->recordSuccess();

            $body = $response->json();

            return is_array($body) ? $body : null;
        }

        return null;
    }

    /** Exponential backoff with up to 25% jitter, clamped to $maxDelay. */
    private function backoffMs(int $attempt, int $baseDelay, int $maxDelay): int
    {
        $delay = min($maxDelay, $baseDelay * (2 ** ($attempt - 1)));

        return (int) min($maxDelay, $delay + random_int(0, (int) ($delay / 4)));
    }

    /**
     * `Retry-After` is either delta-seconds or an HTTP date (RFC 9110 §10.2.3).
     * Both forms show up in the wild.
     */
    private function retryAfterMs(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header * 1000;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return max(0, ($timestamp - time()) * 1000);
    }

    /*
    |--------------------------------------------------------------------------
    | Client-side rate limiting
    |--------------------------------------------------------------------------
    */

    /**
     * Sleep out the remainder of this provider's minimum inter-request
     * interval. MusicBrainz publishes a hard 1 req/sec and will block a client
     * that ignores it, so the throttle is not optional there.
     *
     * The last-call clock lives in the shared cache so parallel workers see
     * each other, but read-then-write is not atomic: under heavy concurrency
     * two workers can slip through together. For strict providers run the
     * `sync` queue with a single worker.
     */
    private function throttle(): void
    {
        $requests = max(1, (int) $this->setting('rate_limit.requests', 10));
        $perSeconds = max(1, (int) $this->setting('rate_limit.per_seconds', 1));
        $minIntervalMs = (int) ceil(($perSeconds * 1000) / $requests);

        $key = $this->cacheKey('throttle');
        $lastCallMs = (int) $this->cache->get($key, 0);
        $nowMs = (int) (microtime(true) * 1000);
        $elapsed = $nowMs - $lastCallMs;

        if ($lastCallMs > 0 && $elapsed < $minIntervalMs) {
            Sleep::for($minIntervalMs - $elapsed)->milliseconds();
            $nowMs += $minIntervalMs - $elapsed;
        }

        // A minute is far longer than any sane interval; the entry is only a clock.
        $this->cache->put($key, $nowMs, 60);
    }

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    */

    protected function circuitIsOpen(): bool
    {
        return (bool) $this->cache->get($this->cacheKey('circuit'), false);
    }

    /**
     * Count a failed exchange. Once the counter reaches the threshold the
     * circuit opens for the cooldown and every later call short-circuits, so a
     * provider that is down costs us one round of timeouts rather than one per
     * record in the batch.
     */
    private function recordFailure(): void
    {
        $threshold = max(1, (int) $this->setting('circuit_breaker.failure_threshold', 5));
        $window = max(1, (int) $this->setting('circuit_breaker.failure_window_seconds', 600));
        $cooldown = max(1, (int) $this->setting('circuit_breaker.cooldown_seconds', 300));

        $failures = ((int) $this->cache->get($this->cacheKey('failures'), 0)) + 1;
        $this->cache->put($this->cacheKey('failures'), $failures, $window);

        if ($failures < $threshold) {
            return;
        }

        $this->cache->put($this->cacheKey('circuit'), true, $cooldown);
        $this->cache->forget($this->cacheKey('failures'));

        $this->logger->error('Provider circuit opened', [
            'provider' => $this->key(),
            'consecutive_failures' => $failures,
            'cooldown_seconds' => $cooldown,
        ]);
    }

    /** One good response clears the record — the breaker counts *consecutive* failures. */
    private function recordSuccess(): void
    {
        if ($this->cache->get($this->cacheKey('failures')) !== null) {
            $this->cache->forget($this->cacheKey('failures'));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Token cache helpers (used by the OAuth/JWT adapters)
    |--------------------------------------------------------------------------
    */

    protected function cachedToken(): ?string
    {
        $token = $this->cache->get($this->cacheKey('token'));

        return is_string($token) && $token !== '' ? $token : null;
    }

    protected function rememberToken(string $token, int $ttlSeconds): void
    {
        $this->cache->put($this->cacheKey('token'), $token, max(1, $ttlSeconds));
    }

    protected function forgetToken(): void
    {
        $this->cache->forget($this->cacheKey('token'));
    }

    protected function cacheKey(string $suffix): string
    {
        return implode(':', [self::CACHE_NAMESPACE, $this->key(), $suffix]);
    }

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    /**
     * Log a provider failure (11_PROVIDER_INTEGRATION §9) with the URL and
     * context stripped of anything secret.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logFailure(string $reason, string $url, array $context = []): void
    {
        $this->logger->warning('Provider request failed', array_merge([
            'provider' => $this->key(),
            'reason' => $reason,
            'url' => $this->safeUrl($url),
        ], $this->scrub($context)));
    }

    /**
     * Strip the query string down to its non-sensitive parameters. Last.fm puts
     * its API key in the query, so logging a raw URL would leak a credential
     * into the log file.
     */
    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '[unparseable url]';
        }

        $safe = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');

        if (! isset($parts['query'])) {
            return $safe;
        }

        parse_str($parts['query'], $query);

        /** @var array<string, mixed> $query */
        return $safe.'?'.http_build_query($this->scrub($query));
    }

    /**
     * Replace the value of any key that looks like a secret. Recursive, because
     * context arrays are occasionally nested.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function scrub(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->scrub($value);

                continue;
            }

            if ($this->isSensitive((string) $key)) {
                $context[$key] = '[redacted]';
            }
        }

        return $context;
    }

    private function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Mapping helpers shared by the concrete adapters
    |--------------------------------------------------------------------------
    */

    /**
     * Pull a nested value out of a decoded payload without a chain of isset()s.
     *
     * @param  array<array-key, mixed>  $payload
     */
    protected function dig(array $payload, string $path, mixed $default = null): mixed
    {
        return data_get($payload, $path, $default);
    }

    protected function str(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Clamp a provider's popularity onto the 0–100 scale the schema stores
     * (`unsignedTinyInteger`). Providers using other ranges rescale before
     * calling this.
     */
    protected function popularity(mixed $value): ?int
    {
        $value = $this->int($value);

        return $value === null ? null : max(0, min(100, $value));
    }

    /**
     * Widen a partial provider date to `Y-m-d`. Spotify returns `1975`,
     * `1975-11` or `1975-11-21` depending on how precisely it knows the release;
     * MusicBrainz does the same. Missing parts default to 1 January so the
     * column stays a real date and year filters still work.
     */
    protected function date(mixed $value): ?string
    {
        $value = $this->str($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', $value, $matches) !== 1) {
            return null;
        }

        return sprintf(
            '%04d-%02d-%02d',
            (int) $matches[1],
            (int) ($matches[2] ?? 1) ?: 1,
            (int) ($matches[3] ?? 1) ?: 1,
        );
    }
}
