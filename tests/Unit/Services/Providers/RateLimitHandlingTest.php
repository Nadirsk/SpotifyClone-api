<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Providers;

use App\Exceptions\ProviderUnavailableException;
use App\Services\Providers\JioSaavn\JioSaavnAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * How AbstractProviderAdapter behaves when a provider says "too many requests".
 *
 * Written against the failure actually observed in production: the public
 * JioSaavn wrapper is a free-tier Cloudflare Worker, and once its daily
 * allowance is spent every endpoint answers 429 with no `Retry-After` until
 * midnight UTC. The old loop met that with five attempts and four sleeps *per
 * request*, inline on a user's search, and every following search repeated the
 * whole performance from scratch.
 *
 * Two properties stop that, and both are asserted below: one refusal costs one
 * request, and it is remembered for everyone. A third — that a refusal is
 * reported as a refusal rather than as an empty catalog — is what keeps the
 * outage from being mistaken for missing data.
 */
class RateLimitHandlingTest extends TestCase
{
    private const COOLDOWN_KEY = 'providers:jiosaavn:cooldown';

    private const STRIKES_KEY = 'providers:jiosaavn:strikes';

    private const THROTTLE_KEY = 'providers:jiosaavn:throttle';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        config([
            'providers.jiosaavn.enabled' => true,
            'providers.jiosaavn.base_url' => 'https://provider.test/api',
            // Spacing is its own concern; keep it out of the cooldown tests.
            'providers.jiosaavn.rate_limit.requests' => 1_000,
            'providers.jiosaavn.rate_limit.per_seconds' => 1,
            'providers.jiosaavn.rate_limit.max_wait_ms' => 2_000,
            'providers.jiosaavn.rate_limit.cooldown_ms' => 30_000,
            'providers.jiosaavn.rate_limit.cooldown_max_ms' => 900_000,
        ]);
    }

    private function adapter(): JioSaavnAdapter
    {
        return app(JioSaavnAdapter::class);
    }

    /** What the wrapper actually returns once its Cloudflare quota is gone. */
    private function fakeRateLimited(): void
    {
        Http::fake(['*' => Http::response('error code: 1027', 429)]);
    }

    private function fakeEmptySuccess(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['total' => 0, 'results' => []]])]);
    }

    /** Run a call that is expected to be refused, and hand back the refusal. */
    private function refusedSearch(string $term = 'demo'): ProviderUnavailableException
    {
        try {
            $this->adapter()->searchSongs($term, 50);
        } catch (ProviderUnavailableException $exception) {
            return $exception;
        }

        $this->fail('Expected the provider to report itself unavailable.');
    }

    /** How much longer the provider is parked for, in milliseconds. */
    private function parkRemainingMs(): int
    {
        return (int) Cache::get(self::COOLDOWN_KEY, 0) - (int) (microtime(true) * 1000);
    }

    /** As if the park had run its course, leaving the strike count behind. */
    private function expirePark(): void
    {
        Cache::forget(self::COOLDOWN_KEY);
    }

    public function test_a_rate_limited_response_is_not_retried(): void
    {
        $this->fakeRateLimited();

        $this->refusedSearch();

        Http::assertSentCount(1);
    }

    public function test_a_refusal_is_reported_as_unavailable_not_as_an_empty_catalog(): void
    {
        $this->fakeRateLimited();

        $exception = $this->refusedSearch();

        $this->assertSame('jiosaavn', $exception->provider);
        $this->assertSame('rate_limited', $exception->reason);
        $this->assertSame(30, $exception->retryAfterSeconds);
        // 503, so that even an uncaught one reads as "come back later" rather
        // than as a broken platform.
        $this->assertSame(503, $exception->getStatusCode());
        $this->assertSame('30', $exception->getHeaders()['Retry-After'] ?? null);
    }

    public function test_a_genuine_miss_is_still_an_empty_result_not_an_exception(): void
    {
        // The other half of the contract: the provider answered, and the answer
        // was "nothing". Nothing to fall back from.
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'no results'], 200)]);

        $this->assertSame([], $this->adapter()->searchSongs('nothing matches this', 50));
    }

    public function test_one_rate_limit_parks_the_provider_for_every_later_caller(): void
    {
        $this->fakeRateLimited();

        $this->refusedSearch('demo');

        // The park outlasts `max_wait_ms`, so these are refused without a call.
        foreach (['another term', 'a third term'] as $term) {
            $this->refusedSearch($term);
        }

        Http::assertSentCount(1);
        $this->assertFalse($this->adapter()->isAvailable());
    }

    public function test_the_park_lengthens_while_the_provider_keeps_refusing(): void
    {
        $this->fakeRateLimited();

        $this->refusedSearch('one');
        $this->assertEqualsWithDelta(30_000, $this->parkRemainingMs(), 1_000);

        $this->expirePark();
        $this->refusedSearch('two');
        $this->assertEqualsWithDelta(60_000, $this->parkRemainingMs(), 1_000);

        $this->expirePark();
        $this->refusedSearch('three');
        $this->assertEqualsWithDelta(120_000, $this->parkRemainingMs(), 1_000);
    }

    public function test_the_park_never_exceeds_its_ceiling(): void
    {
        config(['providers.jiosaavn.rate_limit.cooldown_max_ms' => 45_000]);
        $this->fakeRateLimited();

        for ($i = 0; $i < 4; $i++) {
            $this->expirePark();
            $this->refusedSearch("term {$i}");
        }

        $this->assertEqualsWithDelta(45_000, $this->parkRemainingMs(), 1_000);
    }

    public function test_a_longer_retry_after_header_wins_over_our_own_curve(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '120'])]);

        $this->refusedSearch();

        $this->assertEqualsWithDelta(120_000, $this->parkRemainingMs(), 1_000);
    }

    public function test_a_success_clears_the_park_and_resets_the_escalation(): void
    {
        // One fake, not two: a second Http::fake() call appends its stub behind
        // the first rather than replacing it, and the earlier `*` match wins.
        Http::fake(['*' => Http::sequence()
            ->push('error code: 1027', 429)
            ->push(['success' => true, 'data' => ['total' => 0, 'results' => []]]),
        ]);

        $this->refusedSearch('one');
        $this->assertSame(1, Cache::get(self::STRIKES_KEY));

        $this->expirePark();
        $this->adapter()->searchSongs('two', 1);

        $this->assertNull(Cache::get(self::STRIKES_KEY));
        $this->assertNull(Cache::get(self::COOLDOWN_KEY));
        $this->assertTrue($this->adapter()->isAvailable());
    }

    public function test_requests_are_spaced_by_the_configured_interval(): void
    {
        config([
            'providers.jiosaavn.rate_limit.requests' => 2,
            'providers.jiosaavn.rate_limit.per_seconds' => 1,
        ]);
        $this->fakeEmptySuccess();

        $this->adapter()->searchSongs('one', 1);
        $this->adapter()->searchSongs('two', 1);

        Http::assertSentCount(2);
        // The first call owns the current slot outright; only the second waits.
        Sleep::assertSleptTimes(1);
    }

    public function test_a_backed_up_schedule_sheds_the_call_rather_than_blocking(): void
    {
        config(['providers.jiosaavn.rate_limit.max_wait_ms' => 500]);

        // As if other workers had already reserved every slot for five seconds.
        Cache::put(self::THROTTLE_KEY, (int) (microtime(true) * 1000) + 5_000, 60);

        Http::fake();

        $exception = $this->refusedSearch();

        $this->assertSame('throttled', $exception->reason);
        Http::assertNothingSent();
        Sleep::assertNeverSlept();
    }
}
