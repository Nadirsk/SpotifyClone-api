<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Search\SearchEngine;
use App\Search\DatabaseSearchEngine;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Resolves the search driver named by config('search.driver').
 *
 * Adding Elasticsearch means registering one more entry here — nothing that
 * depends on SearchEngine needs to change. See docs/DEFERRED.md §2.
 */
class SearchServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string<SearchEngine>> */
    private const DRIVERS = [
        'database' => DatabaseSearchEngine::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SearchEngine::class, function (): SearchEngine {
            $driver = (string) config('search.driver');

            if (! isset(self::DRIVERS[$driver])) {
                throw new RuntimeException(sprintf(
                    'Unsupported search driver [%s]. Available: %s.',
                    $driver,
                    implode(', ', array_keys(self::DRIVERS)),
                ));
            }

            return $this->app->make(self::DRIVERS[$driver]);
        });
    }
}
