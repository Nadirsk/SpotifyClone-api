<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Contracts\Providers\ProviderAdapter;
use App\Models\Provider;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * The single place anything asks "which providers can I use right now, and in
 * what order?" (11_PROVIDER_INTEGRATION §10).
 *
 * A provider is usable only when BOTH gates open:
 *
 * - its row in the `providers` table is enabled, which is the operational
 *   switch an operator can flip without a deploy; and
 * - its adapter reports `isEnabled()`, meaning the config flag is on and the
 *   credentials are actually present.
 *
 * With no credentials configured and no provider rows enabled, `enabled()`
 * returns an empty list — so today the sync jobs iterate nothing and make no
 * network calls. That is the intended state, not a failure (docs/DEFERRED.md §4).
 *
 * Ordering comes from the `priority` column, ascending: lower runs first, so the
 * highest-trust provider gets to establish a record before the others enrich it.
 *
 * Note on layering: this reads the `providers` table directly rather than
 * through a repository. There is no ProviderRepository contract — `providers`
 * is operator configuration rather than catalog data, and it is read only here.
 */
final class ProviderManager
{
    /** @var array<string, ProviderAdapter> */
    private array $adapters = [];

    /**
     * Memoised for the life of the request/job: the priority ordering must not
     * change halfway through a sync run, and one query is plenty.
     *
     * @var list<Provider>|null
     */
    private ?array $records = null;

    /** @param iterable<ProviderAdapter> $adapters */
    public function __construct(
        private readonly LoggerInterface $logger,
        iterable $adapters = [],
    ) {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->key()] = $adapter;
        }
    }

    /**
     * Every registered adapter, regardless of whether it is usable.
     *
     * @return list<ProviderAdapter>
     */
    public function all(): array
    {
        return array_values($this->adapters);
    }

    public function has(string $apiName): bool
    {
        return isset($this->adapters[$apiName]);
    }

    /** Resolve an adapter by the `api_name` it is registered under. */
    public function get(string $apiName): ?ProviderAdapter
    {
        return $this->adapters[$apiName] ?? null;
    }

    /**
     * Usable adapters in priority order.
     *
     * @return list<ProviderAdapter>
     */
    public function enabled(): array
    {
        $adapters = [];

        foreach ($this->enabledRecords() as $record) {
            $adapter = $this->get($record->api_name);

            if ($adapter === null) {
                // A row for a provider we ship no adapter for. Harmless, but worth knowing.
                $this->logger->warning('Enabled provider has no registered adapter', [
                    'api_name' => $record->api_name,
                ]);

                continue;
            }

            if (! $adapter->isEnabled()) {
                // Switched on in the database but not configured in the environment.
                $this->logger->debug('Provider enabled in database but not configured', [
                    'api_name' => $record->api_name,
                ]);

                continue;
            }

            $adapters[] = $adapter;
        }

        return $adapters;
    }

    /**
     * The `providers` row backing an adapter — the sync engine needs its ID to
     * write mapping records.
     */
    public function record(string $apiName): ?Provider
    {
        foreach ($this->records() as $record) {
            if ($record->api_name === $apiName) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Enabled provider rows, lowest `priority` first.
     *
     * @return list<Provider>
     */
    public function enabledRecords(): array
    {
        return array_values(array_filter(
            $this->records(),
            static fn (Provider $provider): bool => $provider->enabled,
        ));
    }

    /** @return list<Provider> */
    private function records(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        try {
            /** @var list<Provider> $records */
            $records = Provider::query()
                ->orderBy('priority')
                ->orderBy('name')
                ->get()
                ->all();

            return $this->records = $records;
        } catch (QueryException $exception) {
            /*
             | The table is missing or unreachable — a fresh checkout before
             | `migrate`, typically. Callers must degrade to "no providers"
             | rather than fail: a sync job with nothing to sync is a no-op,
             | not an error.
             */
            $this->logger->warning('Could not read the providers table; treating every provider as unavailable', [
                'message' => $exception->getMessage(),
            ]);

            return $this->records = [];
        }
    }
}
