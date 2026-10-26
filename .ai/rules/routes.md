---
paths:
  - routes/api.php
---

# Routes

## Gate business routes behind auth:sanctum + verified
Email verification is enforced from V1 (User implements MustVerifyEmail). Once points/tags CRUD routes exist, wrap them in `Route::middleware(['auth:sanctum', 'verified'])`, not just `auth:sanctum`. `/user`, `/logout`, and the verification routes themselves stay auth:sanctum-only (or public) — `verified` is only for business/data endpoints, not account-management ones.

## Scramble only documents one HTTP method per apiResource update route
`Route::apiResource()` registers `update` as a single route with methods `['PUT', 'PATCH']`. Scramble's default `operationMethodsResolver` only documents the first method (`$route->methods()[0]`), so generated docs (`/docs/api`) show only `PUT`, hiding that `PATCH` also works.

Fixed once in `app/Providers/AppServiceProvider.php::boot()` via `Scramble::configure()->resolveOperationMethodsUsing(...)`, which lists every non-HEAD method on the route so both `PUT` and `PATCH` show up for every resource (`points`, `tags`, and any future one). Don't re-diagnose this if the docs ever look incomplete for a new resource — check that provider first.
