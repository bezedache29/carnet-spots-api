---
paths:
  - 'app/Http/Controllers/**'
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
