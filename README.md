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
php artisan queue:work --queue=sync,search,default
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
