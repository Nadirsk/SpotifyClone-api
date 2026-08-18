# Music Discovery Platform — API

Laravel 12 / PHP 8.3 REST API for the Music Discovery Platform.

Architecture and requirements live in the numbered documents one level up
(`../01_PRODUCT_REQUIREMENTS.md` … `../11_PROVIDER_INTEGRATION.md`).

- Code conventions: [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md)
- What is deliberately not built yet: [`docs/DEFERRED.md`](docs/DEFERRED.md)

---

## Local setup

This machine's global `php` is XAMPP's 7.4, which cannot run Laravel 12. Use
Herd's PHP 8.3 explicitly:

```powershell
$env:PATH = "C:\Users\AAIBUZZ 1\.config\herd\bin\php83;" + $env:PATH
```

Every command below assumes that is on the PATH.

### 1. Start MySQL

XAMPP's MariaDB is not registered as a Windows service:

```powershell
Start-Process "C:\xampp\mysql\bin\mysqld.exe" `
  -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini','--standalone' -WindowStyle Hidden
```

### 2. Databases

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS music_discovery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS music_discovery_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 3. Install & migrate

```powershell
composer install
php artisan key:generate      # only if APP_KEY is empty
php artisan migrate --seed
php artisan serve             # http://localhost:8000
```

### 4. Queue worker

Long-running work (provider sync, trending, cleanup) is queued. Horizon is
deferred until Redis exists, so run the plain worker:

```powershell
php artisan queue:work --queue=sync,default,notifications
php artisan schedule:work     # in another terminal
```

---

## Stack

| Concern | Now | Production target |
|---|---|---|
| Database | MySQL / MariaDB | MySQL |
| Cache | `database` store | Redis |
| Queue | `database` driver | Redis + Horizon |
| Search | MySQL FULLTEXT | Elasticsearch 8 |
| Auth | Sanctum bearer tokens | same |

The cache/queue/search rows differ from the architecture docs on purpose — see
`docs/DEFERRED.md` for why, and for exactly what it takes to close each gap.

---

## Layout

```
app/
├── Contracts/          Interfaces the app layer depends on
│   ├── Providers/      ProviderAdapter
│   ├── Repositories/   One per aggregate
│   └── Search/         SearchEngine
├── DTO/                Immutable data carriers (SearchQuery, CatalogQuery, …)
├── Enums/              SortOrder, PlaylistVisibility
├── Exceptions/         Central API error rendering
├── Http/
│   ├── Controllers/Api/V1/
│   ├── Middleware/     RequestId, ForceJson, SetLocale, LogApiRequests
│   ├── Requests/       All validation
│   └── Resources/      All serialisation
├── Jobs/               Queued sync / trending / cleanup
├── Models/
├── Policies/
├── Repositories/       Eloquent implementations
├── Search/             DatabaseSearchEngine
├── Services/           Business logic
│   ├── Auth/  Cache/  Catalog/  Library/  Providers/  Search/  Sync/  Trending/  User/
└── Traits/             ApiResponse
```

Request flow: `Route → Controller → Service → Repository → Model`, with a
FormRequest validating and a Resource serialising.

---

## API

Base URL `/api/v1`. Bearer token auth via Sanctum.

Every response uses one envelope:

```json
{ "success": true, "message": "Request successful", "data": {} }
```

```json
{ "success": false, "message": "Validation failed", "errors": { "field": ["…"] } }
```

Paginated responses add:

```json
{ "pagination": { "page": 1, "limit": 20, "total": 240, "last_page": 12 } }
```

Rate limits: 60 req/min for guests, 300 req/min authenticated.

Full endpoint reference: `../05_API_SPECIFICATION.md`, and the OpenAPI 3.1
document in `docs/openapi.yaml`.

---

## Testing

Tests run against **MySQL**, not sqlite — the search engine uses FULLTEXT
`MATCH … AGAINST`, which sqlite cannot parse.

```powershell
php artisan test
vendor/bin/pint          # PSR-12 formatting
```

---

## Providers

All five metadata providers (Spotify, Apple Music, Deezer, MusicBrainz, Last.fm)
are implemented behind `App\Contracts\Providers\ProviderAdapter` and are
**disabled by default** — no credentials are configured, so nothing reaches the
network. Development data comes from the seeders.

To enable one: set its credentials and `*_ENABLED=true` in `.env`, and enable its
row in the `providers` table.

Provider IDs are never exposed to clients; they live only in the
`provider_*_mappings` tables.

---

## Catalog crawl (JioSaavn)

The catalog is built by crawling JioSaavn, not by seeding fixtures. Two halves,
deliberately separate:

- **Discovery** — `catalog_crawl_targets` is a work list ("frontier") of things
  known-of but not yet fetched. A seed term surfaces artists, albums and
  playlists; each artist yields their full discography; each album and playlist
  yields tracks; every artist credited on those tracks is queued in turn. There
  is no "list everything" endpoint, so completeness is reached by closure.
- **Freshness** — the existing `Sync*Job`s refresh records already stored, and
  `DiscoverNewReleasesJob` re-opens cheap newest-first probes so new releases
  land automatically.

### 1. Start the local provider wrapper (required)

JioSaavn publishes no developer API. The community wrapper is checked out at
`tools/jiosaavn-api` and **must be running** before any crawl:

```powershell
cd tools\jiosaavn-api
npm install --ignore-scripts
npx tsc; npx tsc-alias      # produces dist/
.\start-local.cmd           # http://127.0.0.1:3500/api
```

Self-hosting is not optional for a full crawl. The shared public instance
(`saavn.sumit.co`) is a free-tier Cloudflare Worker whose *daily* request
allowance is pooled across every user pointing at it; a crawl is millions of
requests and exhausts it within minutes. Point `JIOSAAVN_BASE_URL` at the public
instance only for light use, and drop the rate limits back to ~2/s if you do.

### 2. Seed and run

```powershell
php artisan catalog:crawl --seed      # queue the seed terms (once)
php artisan catalog:crawl --status    # frontier + catalog counts
```

Then let the scheduler drive it — this is the real path:

```powershell
php artisan queue:work --queue=sync,default,notifications
php artisan schedule:work     # in another terminal
```

`CrawlFrontierJob` runs every 5 minutes and claims a bounded batch, so
throughput scales with worker count and schedule frequency rather than any one
run going long. Kill either process at any time: progress lives in the table,
leases expire, and the next tick resumes at the exact page it stopped on.

To watch it work in the foreground instead:

```powershell
php artisan catalog:crawl --drain --max=2    # 2 batches, then stop
php artisan catalog:crawl --reset-failed     # retry parked targets
```

### Provider ceilings worth knowing

Measured against the live API — these bound completeness and are not bugs:

| Listing | Ceiling |
|---|---|
| Search results per page | 40, whatever `limit` says |
| Search depth | ~1,000 **distinct** per term; `total` counts repeats (a "sachin jigar" walk reports 1,884 and yields 1,014 distinct, some IDs recurring 22×) |
| Artist songs / albums | 10 per page, no override |
| Playlist tracks | **50 per playlist, total** — JioSaavn's own `list_count` caps there even when asked for 100 |
| Playlist paging | 1-based; `page=0` and `page=1` return the same records |

Search is therefore a *seed* for discovery, not the road to completeness — the
records past its depth limit are reached through discographies, album
tracklists and playlists, all of which page honestly.
