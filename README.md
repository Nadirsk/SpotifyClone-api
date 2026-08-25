# Music Streaming Platform — API

Laravel 12 / PHP 8.3 REST API for the Music Streaming Platform.

Architecture and requirements live in the numbered documents one level up
(`../01_PRODUCT_REQUIREMENTS.md` … `../11_PROVIDER_INTEGRATION.md`).

- Code conventions: [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md)
- What is deliberately not built yet: [`docs/DEFERRED.md`](docs/DEFERRED.md)

---

## Local setup

Use Herd's PHP 8.3 for every `php`/`composer` command:

```powershell
$env:PATH = "C:\Users\AAIBUZZ 1\.config\herd\bin\php83;" + $env:PATH
```

Every command below assumes that is on the PATH. (XAMPP's bundled PHP is
8.2.12 — it does run this app, and it is what serves it over Apache in step 4,
but keep the CLI on 8.3 so `artisan` matches the version in `composer.json`'s
`require`. See step 4 for what that split does and does not permit.)

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
```

### 4. Serve the API — **not** with `php artisan serve`

```powershell
Start-Process "C:\xampp\apache\bin\httpd.exe" -WindowStyle Hidden   # http://127.0.0.1:8000
```

`artisan serve` uses PHP's built-in server, which is **single-threaded**: it
finishes one request before it starts the next, and `PHP_CLI_SERVER_WORKERS`
does not help because it needs `fork()`. That is fine for one request at a
time and actively broken for this app, whose pages fan out to a dozen or more
API calls at once. Measured against `/api/v1/genres`, eight parallel requests
came back at 2.9s, 3.2s, 4.0s, 4.4s, 4.8s, 5.4s, 5.8s and 7.5s — a perfect
ladder, one request per second, queued behind each other. Twenty of them puts
the last one past the frontend's 20s Axios timeout, so the browser aborts it,
and the page loses a panel to a request the server would eventually have
answered.

Apache serves the same port from a thread pool instead. Same twenty requests,
slowest 0.92s. The configuration is already in place:

| File | What it does |
|---|---|
| `C:\xampp\apache\conf\httpd.conf` | `Listen 127.0.0.1:8000` — loopback only, exactly as `artisan serve` bound it |
| `C:\xampp\apache\conf\extra\httpd-vhosts.conf` | vhost for `backend/public`, `AllowOverride All` so Laravel's `.htaccess` front controller works |
| `C:\xampp\php\php.ini` | opcache enabled (`zend_extension=opcache`) |

Port 8000 is deliberate: `NEXT_PUBLIC_API_URL`, `APP_URL`, the CORS config and
`next.config.ts`'s image `remotePatterns` all already point there, so switching
servers needed no other change.

opcache matters nearly as much as the threading. Without it Laravel recompiles
every file on every request — that was the whole of the 1.1s each of those
ladder steps cost. With it a warm request is 0.06–0.2s at a 99.6% hit rate.
`opcache.validate_timestamps=1` with `revalidate_freq=0` keeps edits picked up
immediately, so it is safe for development.

The web SAPI is XAMPP's PHP 8.2.12 while the CLI uses Herd's 8.3. That split is
tolerable only because `composer.json` requires `^8.2`, `composer
check-platform-reqs` passes clean on 8.2.12, and nothing in `app/` uses 8.3-only
syntax. Introducing typed class constants, `#[Override]` or `json_validate()`
would run under `artisan` and 500 in the browser — if you need them, move Apache
onto Herd's PHP via FastCGI first.

To stop Apache: `Stop-Process -Name httpd -Force`.

### 5. Queue worker

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

### Catalog repair commands

The crawler's job is discovery; these fix things a *previous* version of the
sync path got wrong, or fill in what a search-sourced record cannot know. All of
them are re-runnable, none deletes a song, and the three that talk to the
provider need the local wrapper running.

| Command | What it fixes | Provider calls |
|---|---|---|
| `catalog:backfill-credits` | Populates `song_credits` for songs synced before that table existed. Songs are batched 50 provider IDs per request, so the whole catalog is ~730 calls. | yes |
| `catalog:repair-display-artists` | Relabels songs whose `artist_id` is the lyricist or composer rather than the singer. Reads only stored credits — run `backfill-credits` first. | no |
| `catalog:repair-tracklists` | Re-fetches album tracklists to fix duplicate `track_number` values (`--scope=duplicates`, the default) or to fill in the ~86% that are null because the song was found by search (`--scope=missing`). | yes |
| `catalog:split-merged-albums` | Un-merges albums holding several languages. A film soundtrack is released once per language under the *same* title, and title-based dedup welded them into one row — "M.S. Dhoni - The Untold Story" held 27 tracks across four languages. | no |
| `catalog:enrich-artists` | Fills bio/image/popularity for artists that exist only as a bare-name stub from a song or album sync. | yes |
| `catalog:parse-soundtracks` | Extracts `film_title` out of song titles. | no |

Order matters for two of them: `repair-display-artists` reads what
`backfill-credits` writes, and both are cheaper to run after the crawl has
settled than during it.

Every one takes `--limit` and most take `--dry-run`. Use the dry run — several
of these move data between rows, and the summary tells you how much before it
does.

#### An artist's songs is now a credits query

`GET /artists/{id}/songs` returns everything an artist is credited on in a music
role, not only the songs whose display artist is them. A music director's page
went from 17 songs to 51; a lyricist's from 67 to 452. `starring` credits are
stored and excluded from that query — a film actor did not make the music.

`song_credits` is maintained as a complete superset of `songs.artist_id`, which
is what lets the query be one indexed lookup instead of an `OR` across two
access paths (measured: 1,078ms → 66ms on the artist endpoint). `SongObserver`
keeps that invariant true on every song write, so a factory-made song in a test
has it too.
