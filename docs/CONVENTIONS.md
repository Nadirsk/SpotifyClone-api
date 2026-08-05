# Backend Conventions

Read this before adding code. It describes patterns that already exist in the
repo — follow them rather than inventing a parallel style.

## Non-negotiables

- `declare(strict_types=1);` at the top of **every** PHP file, after `<?php`.
- PSR-12. Run `vendor/bin/pint` before finishing.
- Constructor property promotion with `private readonly` for injected deps.
- No business logic in controllers. No database access in services except
  through a repository. No provider HTTP calls outside an adapter.
- UUID primary keys everywhere (`Illuminate\Database\Eloquent\Concerns\HasUuids`).
- Never expose a provider's own ID to a client. Mapping tables only.
- `Model::preventLazyLoading()` is on outside production — **eager-load
  relationships** or the request throws.

## Layers

```
Route → Controller → Service → Repository → Model
                  ↘ FormRequest (validation)
                  ↘ Resource (serialisation)
```

- **Controller**: resolves input, calls one service method, returns via
  `App\Traits\ApiResponse`. Thin — no conditionals beyond guard clauses.
- **FormRequest**: all validation. Never validate inside a controller.
- **Service**: business logic, caching, events. Depends on repository
  *interfaces*, never on concrete classes.
- **Repository**: query building and persistence. Returns models/collections.
- **Resource**: the JSON shape. Never leak a column the API spec does not list.

## Response envelope

Use the `App\Traits\ApiResponse` trait — never build the array by hand.

```php
$this->respondSuccess($data, 'Message');        // 200
$this->respondCreated($data, 'Created');        // 201
$this->respondNoContent();                      // 204
$this->respondPaginated($paginator, SongResource::class);
$this->respondError('Message', $errors, 422);
```

Success: `{ success, message, data }`. Paginated adds
`pagination: { page, limit, total, last_page }`. Errors are produced centrally by
`App\Exceptions\ApiExceptionRenderer` — throw, do not catch-and-format.

## Naming

| Thing | Pattern | Example |
|---|---|---|
| Controller | `<Entity>Controller` | `SongController` |
| Service | `<Entity>Service` | `SongService` |
| Repo interface | `App\Contracts\Repositories\<Entity>Repository` | `SongRepository` |
| Repo impl | `App\Repositories\Eloquent<Entity>Repository` | `EloquentSongRepository` |
| Request | `<Verb><Entity>Request` | `StorePlaylistRequest` |
| Resource | `<Entity>Resource` | `SongResource` |
| DTO | `App\DTO\<Name>` | `SongData` |
| Job | `<Verb><Noun>Job` | `SyncSongsJob` |

Bind every repository interface to its implementation in
`App\Providers\RepositoryServiceProvider`.

## Caching

Go through `App\Services\Cache\CacheService`, never the `Cache` facade directly.
TTLs live in `config/music.php` — do not hardcode seconds.

The `database` cache store does **not** support tags, so invalidate by explicit
key. Do not introduce `Cache::tags()`.

## Config

No magic numbers. Pagination, TTLs, rate limits, trending weights and playlist
caps are all in `config/music.php` / `config/search.php`.

## Comments

Explain *why*, never *what*. A comment restating the code is noise. Comment the
non-obvious: a fallback that exists for a MySQL limitation, a security-motivated
ordering, a deliberate deviation from the architecture docs.

## Testing

- Feature tests for every endpoint: happy path, validation failure, auth
  failure, and authorization failure where a policy applies.
- Unit tests for service logic that has branches worth pinning.
- `RefreshDatabase`. Build data with factories, never raw inserts.
- Assert on the envelope (`success`, `message`, `data`) — not just the status.

## Scope discipline

Build MVP only. Anything under "Future Features" in `01_PRODUCT_REQUIREMENTS.md`
(AI recommendations, voice search, podcasts, artist uploads, premium, offline,
social, collaborative playlists, lyrics) is **out of scope** until explicitly
requested. `/recommendations` is Phase 2 — do not implement it.

Redis, Elasticsearch and Horizon are deferred — see `docs/DEFERRED.md`. Do not
add those dependencies.
