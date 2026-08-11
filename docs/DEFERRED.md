# Deferred Work

Things the architecture docs call for that are **intentionally not built yet**, with the
reason and what it will take to finish them. Nothing here is an accident — do not
"fix" these by quietly adding a dependency.

---

## 1. Redis (cache, sessions, queues, rate limiting)

**Status:** deferred until the production deploy.
**Why:** Redis is not installed on the development machine and there is no Docker.
**Docs:** `02_SYSTEM_ARCHITECTURE` §7, `10_DEPLOYMENT_DEVOPS` §9.

Today the app runs on `CACHE_STORE=database`, `QUEUE_CONNECTION=database`,
`SESSION_DRIVER=database`.

To finish:

1. Provision Redis, set `REDIS_HOST` / `REDIS_PASSWORD` / `REDIS_PORT`.
2. Set `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.
3. Install `predis/predis` or the `phpredis` extension.
4. Nothing in application code should need to change — everything caches through
   `App\Services\Cache\CacheService`, which uses the `Cache` facade.

Note: the `database` cache store does not support tag-based flushing, so
`CacheService` invalidates by explicit key rather than by tag. That works on Redis
too, so no rewrite is needed — but tags become available if you want them later.

---

## 2. Elasticsearch

**Status:** deferred until the production deploy.
**Why:** not installed locally, no Docker.
**Docs:** `06_SEARCH_ARCHITECTURE`.

Search currently runs through `App\Search\DatabaseSearchEngine`, which uses MySQL
FULLTEXT indexes. It covers fuzzy-ish matching, prefix autocomplete, filters,
ranking and pagination — enough to build and test the whole API against.

What MySQL FULLTEXT does **not** give us, and Elasticsearch will:

- real typo tolerance / edit-distance matching (`beliver` → `Believer`)
- synonym expansion (`hiphop` → `hip-hop`, `rap`)
- per-language analyzers for the non-Latin scripts in §7
- tunable relevance scoring

To finish:

1. Provision Elasticsearch 8.x, set `ELASTICSEARCH_HOSTS`.
2. Add `App\Search\ElasticsearchSearchEngine implements App\Contracts\Search\SearchEngine`.
   The interface is already the only thing the application layer depends on.
3. Register it in `App\Providers\SearchServiceProvider` under the `elasticsearch` key.
4. Set `SEARCH_DRIVER=elasticsearch`.
5. Implement index creation + mappings, then run the reindex job.

---

## 3. Laravel Horizon

**Status:** deferred — depends on Redis.
**Why:** Horizon requires a Redis queue connection.
**Docs:** `04_BACKEND_ARCHITECTURE` §8, `10_DEPLOYMENT_DEVOPS` §11.

Queue jobs are written against Laravel's queue abstraction and run fine on the
`database` driver. Once Redis lands, `composer require laravel/horizon` and point
the queues (`sync`, `search`, `notifications`, `recommendations`, `default`) at it.

---

## 4. Live provider sync

**Status:** JioSaavn and iTunes are live (2026-08-07); the rest still need
credentials.
**Why:** these two need no API key at all — everything else (Spotify, the
paid Apple Music catalog API, MusicBrainz, Last.fm) does, and none are
available yet.
**Docs:** `11_PROVIDER_INTEGRATION`.

Six adapters implement `App\Contracts\Providers\ProviderAdapter`:
`App\Services\Providers\JioSaavn\JioSaavnAdapter` (community wrapper around
JioSaavn's own catalog — no official API exists) and
`App\Services\Providers\ITunes\ITunesSearchAdapter` (Apple's free public
Search API — distinct from the paid `AppleMusicAdapter` below) are enabled
(`JIOSAAVN_ENABLED=true`, `ITUNES_ENABLED=true`). Every other provider stays
disabled by default (`*_ENABLED=false`) so nothing calls out to the network
until it is configured.

The catalog's initial content came from `php artisan catalog:bootstrap`
(`app/Console/Commands/BootstrapCatalog.php`) — a one-off command that runs a
curated search-term list through every enabled adapter, because the
incremental jobs (`SyncSongsJob` etc.) only refresh mappings that already
exist and `LazySyncSearchJob` only fires on a live search miss; neither
discovers new content from an empty catalog on its own. The dev-fixture
catalog from `CatalogSeeder` (~40 placeholder artists) has been cleared now
that real data exists, since its static `trending_score` values otherwise
outranked every real synced song on `/trending`.

To enable another provider: fill its credentials in `.env`, set
`*_ENABLED=true`, confirm its row in the `providers` table is enabled, and run
`catalog:bootstrap` again (or wait for a lazy-sync search miss) to give it
something to sync.

---

## 5. Out of MVP scope entirely

Not deferred — deliberately not in scope until the MVP is complete and signed off.
See `01_PRODUCT_REQUIREMENTS` §5 (Future Features) and §11 (Phase 2 / Phase 3):
AI recommendations, voice search, podcasts, artist uploads, premium plans, offline
mode, desktop/mobile apps, social sharing, collaborative playlists, lyrics.

The `/recommendations` endpoints in `05_API_SPECIFICATION` §12 are Phase 2. They
are not implemented.
