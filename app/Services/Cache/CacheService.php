<?php

declare(strict_types=1);

namespace App\Services\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Thin wrapper over the cache so that TTLs come from config and every key is
 * namespaced consistently.
 *
 * Deliberately does not use `Cache::tags()`: the `database` store this app runs
 * on locally does not support tagging, and building on tags would mean a
 * rewrite when Redis lands rather than a config change. Invalidation is by
 * explicit key.
 */
final class CacheService
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @param  string  $bucket  A key under config('music.cache.ttl').
     * @return TValue
     */
    public function remember(string $bucket, string $key, Closure $callback): mixed
    {
        return $this->cache->remember(
            $this->key($bucket, $key),
            $this->ttl($bucket),
            $callback,
        );
    }

    public function forget(string $bucket, string $key): void
    {
        $this->cache->forget($this->key($bucket, $key));
    }

    /**
     * Invalidate a set of keys at once — used when a sync updates an entity
     * that several cached views depend on.
     *
     * @param  list<string>  $keys
     */
    public function forgetMany(string $bucket, array $keys): void
    {
        foreach ($keys as $key) {
            $this->forget($bucket, $key);
        }
    }

    public function ttl(string $bucket): int
    {
        $ttl = config("music.cache.ttl.{$bucket}");

        if (! is_int($ttl)) {
            throw new \InvalidArgumentException(
                "No cache TTL configured for bucket [{$bucket}]. Add it to config/music.php."
            );
        }

        return $ttl;
    }

    private function key(string $bucket, string $key): string
    {
        return implode(':', [config('music.cache.prefix'), $bucket, $key]);
    }
}
