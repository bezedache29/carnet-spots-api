---
paths:
  - 'app/Http/Controllers/**'
  - app/Http/Controllers/PointController.php
  - app/Http/Controllers/SyncController.php
  - app/Http/Controllers/TileController.php
---

# Controllers

## Scope tag_ids by owner when attaching to a point
The `point_tag` pivot has no `owner_id` — nothing at the DB level stops attaching a tag owned by another user to your point. When accepting `tag_ids` input (store/update on points), validate with `Rule::exists('tags', 'id')->where('owner_id', Auth::id())` so a point can never end up tagged with another user's tag.

## Use sync()/syncWithoutDetaching(), never attach(), on replayable endpoints
The `point_tag` pivot has a composite primary key (point_id, tag_id) with no upsert semantics. The mobile client replays queued offline mutations, so the same "attach tag X to point Y" call can arrive twice. Plain `attach()` throws a duplicate-key exception on replay — use `sync()` or `syncWithoutDetaching()` on any endpoint that mutates point/tag relations so replays are idempotent.

## Use HasMiddleware, not authorizeResource(), for policy-based route protection
`app/Http/Controllers/Controller.php` is the empty Laravel 11+ base class — it does NOT extend `Illuminate\Routing\Controller`, so it has no instance `middleware()` method. `AuthorizesRequests::authorizeResource()` calls `$this->middleware(...)` internally and fails at runtime with "Call to undefined method ::middleware()" the moment a route hits the constructor (caught by tests, not by static analysis or route:list).

Instead, implement `Illuminate\Routing\Controllers\HasMiddleware` with a static `middleware(): array` method returning `Illuminate\Routing\Controllers\Middleware` instances, e.g.:
```php
class TagController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Tag::class, only: ['index']),
            new Middleware('can:view,tag', only: ['show']),
            // ...
        ];
    }
}
```
Methods without a bound model (index/create/store) reference the model class string; methods with route-model binding (show/update/destroy) reference the route parameter name. See `app/Http/Controllers/TagController.php` for the working pattern.

## Name every action with a Scramble #[Endpoint] attribute
Every controller action (index/store/show/update/destroy, and any custom action) must carry `#[Endpoint(title: '...', description: '...')]` from `Dedoc\Scramble\Attributes\Endpoint`, following the pattern already on `TagController`/`PointController`: title = "Verb a/an resource" (e.g. "Update a point"), description = one sentence on behavior/constraints.

Without it, Scramble shows no summary and the API doc list falls back to raw operationIds, which look inconsistent (clean `v1.points.index` next to ugly auto-suffixed `point.update_14` for the PUT/PATCH pair — see the routes.md rule on that collision). Setting the title makes every route's doc entry readable and uniform regardless of that underlying id oddity.

Note: `#[Endpoint]` isn't repeatable, so the PUT and PATCH variants of an `apiResource` update share the exact same title/description — that's expected, not a bug to fix.

## Keep the mobile replay contract on points/tags CRUD
The mobile client replays offline changes through the CRUD endpoints (README 5.2). Don't break: store with an existing `uuid` of the same owner returns it (200, or 404 if trashed) instead of creating — looked up in the Form Request *before* the payload rules (`replayedPoint()`/`replayedTag()`), and again after a `UniqueConstraintViolationException` when two identical creations race; update with `base_updated_at` older than the server `updated_at` answers 409 with the current resource (server timestamps only); destroy on an already trashed item answers 204 without re-deleting (routes use `->withTrashed(['destroy'])`). Covered by tests/Feature/Api/OfflineReplayTest.php.

## Touch the point when only its tags change
`sync()` on the point_tag pivot doesn't bump the point's `updated_at`, and `GET /sync` only returns rows whose `updated_at >= since`. Any code path that changes a point's tags must `$point->touch()` when `sync()` reports changes, otherwise the phone never sees the new tags.

The opposite holds for tags: renaming or deleting a tag must NOT touch its points. Clients link points to tags by id (README 5.2), and bumping the points would make a queued offline edit of those points fail with a false 409 (`base_updated_at` no longer matches).

## Sync cursor: server time minus a margin, rounded down, compared with >=
`server_time` is captured before reading, moved back by `SyncController::SERVER_TIME_MARGIN_SECONDS` (a row's `updated_at` is computed in PHP before its commit, so it can become visible after a sync already handed out a later cursor) and rounded down to the second (DB timestamps have no fractions); rows are selected with `updated_at >= since` (withTrashed) so nothing changed in the same second is missed — duplicates are fine, the client upserts. Convert `since` to the app timezone before querying: the query builder binds Carbon without timezone conversion.

## Map tiles: auth in Laravel, bytes from Nginx
`.pmtiles` files live on the `tiles` disk (storage/app/private/tiles, a Coolify persistent volume, never in Git). Never move them to `public/`: downloads must stay behind auth:sanctum + verified. In production `TILES_X_ACCEL_REDIRECT` (Dockerfile ENV) makes `show()` answer an `X-Accel-Redirect` to the `internal` location `/_protected-tiles/` in docker/nginx.conf — keep the prefix, the alias path and the disk root in sync. Keep the `[A-Za-z0-9_-]+` route constraint on `{tile}`: the parameter becomes a file path.
