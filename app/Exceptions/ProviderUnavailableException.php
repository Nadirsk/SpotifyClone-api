<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An external metadata provider could not serve us at all.
 *
 * The distinction from a `null` return is the entire point of this class, and
 * it is not a stylistic one:
 *
 * - **null** means the provider answered and there is no such record. That is a
 *   fact about the catalog, and the correct response is to believe it.
 * - **this** means the provider never answered — rate limited, circuit open, or
 *   unreachable after every retry. That is a fact about the provider, and it
 *   says nothing whatsoever about whether the record exists. The correct
 *   response is to fall back to what we already have.
 *
 * Conflating the two is how an upstream outage turns into apparent data loss: a
 * caller that treats "the provider is down" as "there is nothing there" will
 * happily report an empty catalog, and a caller that caches that answer will
 * keep reporting it long after the provider recovers.
 *
 * ## Why this extends HttpException
 *
 * As a backstop, not as a plan. Every caller is expected to catch this at the
 * provider boundary and degrade to the local catalog — an outage at a metadata
 * provider must never reach a listener, who is playing audio straight from
 * `songs.preview_url` and does not need the provider at all. The 503 only
 * matters if some future caller forgets to catch it, and it exists so that the
 * failure mode of forgetting is an honest "temporarily unavailable" rather than
 * a 500 that reads as "this platform is broken".
 *
 * The message is deliberately generic. Which provider we use, and why it is
 * refusing us, is our operational problem — it belongs in the log context and
 * the readonly properties below, never in a client's error envelope
 * (CLAUDE.md: "Never expose provider-specific schemas to clients").
 */
final class ProviderUnavailableException extends HttpException
{
    /** What a client is told, whatever the underlying reason turns out to be. */
    private const PUBLIC_MESSAGE = 'The music catalog is temporarily unavailable. Please try again shortly.';

    /**
     * @param  string  $provider  Adapter key, for logs — never for clients.
     * @param  string  $reason  `rate_limited`, `circuit_open`, `throttled`,
     *                          `unauthorized` or `unreachable`.
     * @param  int  $retryAfterSeconds  Zero when genuinely unknown, which is
     *                                  itself worth distinguishing from "now".
     */
    private function __construct(
        public readonly string $provider,
        public readonly string $reason,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            statusCode: 503,
            message: self::PUBLIC_MESSAGE,
            headers: $retryAfterSeconds > 0 ? ['Retry-After' => (string) $retryAfterSeconds] : [],
        );
    }

    /** The provider answered 429, or is still parked from an earlier one. */
    public static function rateLimited(string $provider, int $retryAfterMs): self
    {
        return new self($provider, 'rate_limited', self::toSeconds($retryAfterMs));
    }

    /** Enough consecutive failures that the breaker stopped trying. */
    public static function circuitOpen(string $provider, int $remainingMs): self
    {
        return new self($provider, 'circuit_open', self::toSeconds($remainingMs));
    }

    /**
     * Our own outbound schedule is backed up further than the caller's wait
     * budget — self-inflicted, but indistinguishable from the outside, and
     * handled the same way.
     */
    public static function throttled(string $provider, int $waitMs): self
    {
        return new self($provider, 'throttled', self::toSeconds($waitMs));
    }

    /** Connection failures or 5xx that survived every retry. */
    public static function unreachable(string $provider): self
    {
        return new self($provider, 'unreachable', 0);
    }

    /** Credentials rejected. An operator problem, but unavailable all the same. */
    public static function unauthorized(string $provider): self
    {
        return new self($provider, 'unauthorized', 0);
    }

    /**
     * Structured context for the log line a caller writes when it catches this.
     *
     * @return array<string, string|int>
     */
    public function context(): array
    {
        return [
            'provider' => $this->provider,
            'reason' => $this->reason,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }

    /** Rounded up: reporting "retry in 0s" for a 400ms wait invites a hot loop. */
    private static function toSeconds(int $milliseconds): int
    {
        return $milliseconds > 0 ? (int) ceil($milliseconds / 1000) : 0;
    }
}
