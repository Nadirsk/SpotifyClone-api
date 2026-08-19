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

---

## 6. Catalog integrity: what is repaired, and what is left

The sync path wrote some things wrong before it was fixed, and the repair
commands in the README's "Catalog repair commands" table undo most of it. What
they cannot reach is listed here so a later reader does not mistake a known
residue for an unnoticed bug.

**Track positions on 14 albums (0.04%) are still duplicated.** Down from 75.
`catalog:repair-tracklists` renumbered 218 tracks across 64 albums from the
provider's own tracklists, and `catalog:split-merged-albums` cleared 3 more by
un-welding language releases. The remainder splits two ways: 5 albums have no
`provider_album_mappings` row, so there is no tracklist to ask for; the other 9
are title-collision compilations ("Indian Pop", "Vishal Mishra Bollywood Hits")
holding songs the provider's tracklist for that album does not contain. Their
*membership* is wrong, not their ordering, and reassigning membership is
deliberately not something the ordering command does — that write is what caused
the original damage and now belongs solely to the guarded path in
`SyncService::withStableAlbumMembership()`.

**31,509 songs on an album have no `track_number` at all** (86% of songs that
have an album). Not damage: a song search returns songs with no album context,
so the position is unknowable from that payload — see
`ProviderSongData::$trackNumber`. Most of this catalog was discovered by search.
`catalog:repair-tracklists --scope=missing` fixes it at one provider call per
album, which is ~21,300 calls and roughly 1.5 hours against the local wrapper.
Not run. Until it is, album pages fall back to insertion order, which is what
`EloquentSongRepository::forAlbum()` already sorts by.

**333 albums still hold more than one language.** `catalog:split-merged-albums`
moved 851 tracks into 106 sibling albums, but leaves any language group smaller
than `--min-tracks=3` where it is: 466 tracks sit in groups of one or two, where
a sibling album holding a single track is arguably worse than the mix. Lower the
threshold to split them.

**Credits depend on one provider.** `song_credits` has no provider column, and
`CreditWriter` replaces a song's whole credit list when it writes. That is
correct while JioSaavn is the only adapter that parses credits, and would need
the column before a second one did — otherwise the two would take turns deleting
each other's work. Noted rather than built, since an unused column is its own
kind of wrong.

**Featured-artist credits are now stored, and the API publishes them, but no UI
shows them.** `GET /songs/{id}` returns a `credits` array with normalized roles;
the frontend type exists (`SongCredit` in `types/api.ts`). A credits block on the
track menu or the now-playing panel is unbuilt UI work, not a data gap. Note that
`tests/e2e/bug-fixes.spec.ts` currently asserts "View credits" is *absent* from
the track menu — it was removed as a dead entry — so building the panel means
updating that expectation.
