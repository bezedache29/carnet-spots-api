# Carnet de Spots — API

The API powering **Carnet de Spots**, a personal spot-tracking app: pinning precise GPS points (parking spots, overnight spots, scouted locations) — even where no formal address exists — with a free-text note, to find and consult them on-site **without a network connection**.

Built with Laravel 13 (PHP 8.3), pure JSON REST — no Inertia.

## 1. Project context & purpose

A personal app (solo use in V1) for pinning precise GPS points, so they can be found and consulted on-site **without a network connection**.

Two usage phases:

- **Preparation** (at home, stable wifi): searching for spots, adding points via the web client.
- **On the road** (camper van, often offline): browsing/updating via mobile, with no network dependency — this API is therefore only contacted by the mobile client before departure (sync) and after regaining network (replaying queued mutations), never continuously.

## 2. Role of this repo in the overall architecture

**3 separate repositories**, with this API as the single source of truth:

- `carnet-spots-api` (this repo, Laravel) — pure JSON REST API
- `carnet-spots-web` (React + Vite) — HTTP client of this API, used for preparation
- `carnet-spots-mobile` (React Native CLI, bare, Android) — HTTP client of this API (occasional sync) + local SQLite storage for offline field use

## 3. Technical decisions

| Topic         | Decision                                                                                                                            | Rationale                                                          |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------- |
| API style     | Pure JSON REST, **no Inertia**                                                                                                       | Avoids duplicating logic between the web and mobile clients          |
| Auth          | **Laravel Sanctum** (token-based)                                                                                                    | Native Laravel ecosystem, sufficient for personal/multi-device use   |
| Geo search    | **Haversine SQL** + composite `(lat, lng)` index                                                                                     | Sufficient at this scale, no need for PostGIS                        |
| Offline tiles | This API also serves regional **`.pmtiles`** files (Protomaps) as static assets, downloaded by the mobile client before a road trip | See section 5                                                        |

## 4. Data schema (migrations)

### `points`

```php
Schema::create('points', function (Blueprint $table) {
    $table->id();
    $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
    $table->string('title')->nullable();
    $table->decimal('latitude', 10, 7);
    $table->decimal('longitude', 10, 7);
    $table->text('note')->nullable();
    $table->enum('status', ['to_test', 'validated', 'to_avoid', 'already_slept'])
          ->default('to_test');
    $table->dateTime('visited_at')->nullable(); // datetime, not timestamp: any year (see migration 2026_10_26)
    $table->enum('visibility', ['private', 'public'])->default('private');
    $table->timestamps();

    $table->index(['owner_id', 'latitude', 'longitude']);
});
```

### `tags`

```php
Schema::create('tags', function (Blueprint $table) {
    $table->id();
    $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
    $table->string('name');
    $table->timestamps();

    $table->unique(['owner_id', 'name']); // prevents duplicate tags per user
});
```

### `point_tag` (pivot)

```php
Schema::create('point_tag', function (Blueprint $table) {
    $table->foreignId('point_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
    $table->primary(['point_id', 'tag_id']);
});
```

### `photos` (V2, not urgent)

```php
Schema::create('photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('point_id')->constrained()->cascadeOnDelete();
    $table->string('path');
    $table->timestamps();
});
```

**Why `owner_id` and `visibility` exist from V1** (even for solo use): this allows opening the app to other users later without a destructive migration — just additive changes (Laravel policies, moderation on public points, rate limiting).

## 5. The API's role in offline support (tiles + data)

The app must work **without a network in the field**. This API is only involved outside of field use:

### 5.1 Map tiles — Protomaps / `.pmtiles`

- The official OSM tile server forbids bulk/automated downloading for offline use. OSM remains the data source; only the distribution infrastructure changes.
- A regional **`.pmtiles`** file is generated (a targeted extract of the road-trip area, not the whole planet) and **served as a static file by this API**.
- The mobile client downloads this file before departure (over wifi) and reads it locally via `file://` — the API is no longer needed for maps once in the field.
- ODbL attribution (`© OpenStreetMap contributors`) is handled client-side (MapLibre styles).

### 5.2 Application data — sync + mutation replay

- **Downward sync** (`GET /api/v1/sync`): the mobile client fetches **all** of the user's points and tags (not only the road-trip area: a few hundred points weigh a few KB, and a point moved out of an area filter would never reach the phone). The first call returns everything; the next ones send `since` = the `server_time` returned by the previous call and only get what changed, plus the ids of what was deleted.
- **Mutation replay**: the mobile client queues offline changes locally and replays them one by one through the standard CRUD endpoints once network is regained, in the order they were made (a point that uses a tag created offline must wait for that tag's creation). Each change is independent: one that fails (conflict, validation) stays in the phone's queue and doesn't block the others.
  - **Creations** carry a `uuid` generated by the phone: replaying a creation returns the existing point/tag (200) instead of a duplicate — even if the rest of the payload no longer validates (e.g. one of its tags was deleted since), and even if two identical creations arrive at the same time.
  - **Updates** carry `base_updated_at`, the `updated_at` of the version the phone edited. If the item changed on the server since, nothing is saved and the API answers **409** with the current version — the app asks the user what to keep. Only server timestamps are compared, so the phone's clock never matters.
    - After each accepted update, the phone must replace `base_updated_at` in the **remaining queued changes of that item** with the `updated_at` from the response. Otherwise its second offline edit of a point (e.g. note, then status) gets a 409 caused by its own first edit.
    - Known limit: timestamps have no fractions of a second, so two edits of the same item within the same second don't detect each other. Negligible for a single user.
  - **Deletions** are soft deletes (`deleted_at`) so the sync can report them. Deleting an already deleted item answers 204; updating a deleted item answers 404 (the deletion wins, the phone drops its change).

## 6. V1 roadmap for this repo

1. Migrations + Eloquent models (`Point`, `Tag`, `User`)
2. API routes: CRUD `points`, CRUD `tags`, "points near a position" endpoint (Haversine)
3. Sanctum auth (even solo, a single account)
4. Sync endpoint for mobile + mutation replay on the CRUD endpoints
5. Static file service for regional `.pmtiles`

## 7. Out of scope for V1

- Cross-user access policies, moderation of public points (registration exists for convenience — creating the account(s) that use this app — but there's no multi-tenant access control yet)
- Landing page / blog (will be a separate 4th repo, Next.js, if the need is confirmed — independent of this API but a consumer of it)
- Photos attached to points
- Notifications ("point nearby")

## 8. Current implementation status

Roadmap steps 1 (domain layer), 2 (CRUD + nearby search), 3 (Sanctum authentication) and 4 (sync + mutation replay) are done. Next up is step 5 (`.pmtiles` static file serving).

**Done:**

- Laravel 13 skeleton, PHP 8.3, Pest 4 (functional syntax, `RefreshDatabase` on feature tests)
- Laravel Boost installed (AI-agent guidelines, skills, MCP server for this codebase)
- Two project-specific AI agents committed to the repo (`.claude/agents/`):
  - `pest-tester` — writes and runs Pest tests following this project's domain (ownership scoping, geo search, sync/replay)
  - `laravel-code-reviewer` — reviews security/ownership, correctness, performance, and Laravel conventions before merge
- Shared project rules for AI agents and teammates in `.ai/rules/` (indexed in `.ai/rules/index.md`)
- Local dev environment: Laravel Sail (Docker), reverse-proxied through a local Traefik instance, MySQL as the local database (Sail's `mysql` service), phpMyAdmin, and Mailpit to read the emails the app sends (`https://mail-carnet-spots-api.dev.localhost`)
- Production `Dockerfile` (PHP-FPM + Nginx + Supervisor) for staging/production on Coolify, MySQL-backed
- **Roadmap step 1** — domain layer: `points`, `tags`, `point_tag` migrations, `PointStatus` / `PointVisibility` enums, `Point` / `Tag` models with factories and tests
- **Roadmap step 3** — Sanctum token authentication:
  - Register (sends an email verification link), login, logout, current user
  - One token per device: logging in again with the same `device_name` revokes that device's previous token
  - Login throttled to 5 attempts/minute per email + IP; constant-time check so unknown emails can't be told apart from wrong passwords
  - Passwords: 12 characters minimum, rejected if found in a known data breach (HaveIBeenPwned range API, free, no account)
  - Points and tags require a **verified email** (`verified` middleware)
- **Roadmap step 2** — versioned REST endpoints under `/api/v1` (see the table below):
  - CRUD `points`: paginated list (50 per page) with a stable order — `created_at` then `id` — so no point is skipped or repeated across pages; partial `PATCH` updates; tags set through `tag_ids` with `sync()` so replays are idempotent (an id sent twice is silently ignored); creating or updating a point is all-or-nothing — if saving the point or its tags fails, the API answers an error and nothing is changed
  - `visited_at` is always stored in the app timezone (UTC): a value sent with an offset (`2026-07-14T21:00:00+02:00`) is converted, not truncated. The column is a `datetime`, so any year is accepted (a MySQL `timestamp` was limited to 1970–2038)
  - Input limits: `note` ≤ 10 000 characters, tag `name` ≤ 50 characters
  - CRUD `tags` (name unique per owner)
  - Nearby search: Haversine distance in SQL, pre-filtered by the exact spherical bounding box of the search circle so the `(owner_id, latitude, longitude)` index is used; correct at any latitude and radius, including searches that contain a pole or cross the 180th meridian
  - Ownership enforced by `PointPolicy` / `TagPolicy`: another user's point or tag answers **404**, not 403, so its existence is not leaked (checked before the payload is validated). Every API 404 has the same body, `{"message": "Not found."}`, so a missing id and another user's id can't be told apart
  - Points and tags expose `created_at` / `updated_at`, needed for the step 4 sync
- **Roadmap step 4** — sync + mutation replay for the mobile client (rules in section 5.2):
  - `GET /api/v1/sync` returns the points and tags changed since the previous sync, the deleted ones as `{id, uuid}`, and the `server_time` to send next time. `server_time` is 5 seconds in the past and compared with `>=`, so a change still being written during a sync is never lost; the price is that an item may come back twice, so the phone applies them as upserts. `since` must be ISO 8601 (as returned in `server_time`)
  - Points and tags have a client-generated `uuid` (generated by the API when absent; existing rows were backfilled), so a replayed creation is never duplicated
  - Optional `base_updated_at` on updates: 409 with the current version when the item changed on the server since
  - Soft deletes on points and tags; replaying a `DELETE` answers 204. A deleted tag's name can be reused: uniqueness is enforced on a generated `active_name` column that is `NULL` once the tag is deleted
  - Changing only a point's tags, renaming one of its tags or deleting one of its tags bumps the point's `updated_at`, so the sync resends it
  - Rolling back the migration deletes soft-deleted points and tags for good (they would otherwise come back as live rows)
- Feature tests for every endpoint in `tests/Feature/Api/` and `tests/Feature/Auth/`

**API endpoints** (all JSON; every route except `register`, `login` and the email verification link needs an `Authorization: Bearer <token>` header; points and tags also need a verified email):

| Method      | URL                                       | Purpose                                                                 |
| ----------- | ----------------------------------------- | ----------------------------------------------------------------------- |
| `POST`      | `/api/v1/register`                        | `name`, `email`, `password`, `password_confirmation`, `device_name` → `{ token, user }`, sends the verification email |
| `POST`      | `/api/v1/login`                           | `email`, `password`, `device_name` → `{ token, user }`                  |
| `POST`      | `/api/v1/logout`                          | Revoke the token used for this request                                  |
| `GET`       | `/api/v1/user`                            | Current user                                                            |
| `POST`      | `/api/v1/email/verification-notification` | Resend the verification email                                           |
| `GET`       | `/api/v1/email/verify/{id}/{hash}`        | Signed link from the verification email                                 |
| `GET`       | `/api/v1/points`                          | User's points, newest first, 50 per page, with tags. Response: `{ data, links, meta }`; next pages with `?page=2`, … (`meta.last_page` gives the count) |
| `GET`       | `/api/v1/sync`                            | Optional `since` → `{ server_time, points, tags, deleted: { points, tags } }` (see section 5.2) |
| `POST`      | `/api/v1/points`                          | Create a point (`latitude`, `longitude` required; optional `tag_ids`, `uuid`). An existing `uuid` returns that point (200) |
| `GET`       | `/api/v1/points/nearby`                   | `lat`, `lng`, `radius` km (default 20, max 20 000), `limit` (default 50, max 100) → nearest first, with `distance_km` |
| `GET`       | `/api/v1/points/{id}`                     | One point with its tags                                                 |
| `PUT/PATCH` | `/api/v1/points/{id}`                     | Update only the fields sent; `tag_ids` replaces the tags when present; optional `base_updated_at` (409 on conflict) |
| `DELETE`    | `/api/v1/points/{id}`                     | Soft-delete a point (its tags are kept); 204 again if already deleted   |
| `GET`       | `/api/v1/tags`                            | User's tags, alphabetical                                               |
| `POST`      | `/api/v1/tags`                            | Create a tag (`name`; optional `uuid`). An existing `uuid` returns that tag (200) |
| `GET`       | `/api/v1/tags/{id}`                       | One tag                                                                 |
| `PUT/PATCH` | `/api/v1/tags/{id}`                       | Rename a tag; optional `base_updated_at` (409 on conflict)              |
| `DELETE`    | `/api/v1/tags/{id}`                       | Soft-delete a tag (detached from its points, points are kept); 204 again if already deleted |

Interactive documentation of these endpoints is generated by Scramble at `/docs/api` (local environment).

**Not started yet:**

- `.pmtiles` static file serving — roadmap step 5

**Open points to decide later** (raised in code review):

- Soft-deleted points and tags are kept forever. Purging them after a few months (Laravel's `Prunable`) is possible, but a phone that hasn't synced for longer would then never learn about those deletions — to decide once the volume justifies it.
- `bootstrap/app.php` trusts `X-Forwarded-For` from any proxy (`trustProxies(at: '*')`). If the app is reachable without going through Traefik/Coolify, a client could spoof its IP and bypass the login throttle. To check against the Coolify setup.

## 9. Local development

Requires Docker.

On a fresh clone, `vendor/` does not exist yet (so `./vendor/bin/sail` doesn't either). Install the locked PHP dependencies once through Docker, without needing PHP on the host. Any recent PHP + Composer image works for this one-off step (it only downloads packages; `--ignore-platform-reqs` skips the PHP version check) — the app itself then runs in the Sail image:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

```bash
# Start the app (builds the image on first run)
./vendor/bin/sail up -d

# Run the test suite
./vendor/bin/sail pest

# Artisan commands
./vendor/bin/sail artisan migrate
```

The app is reverse-proxied through a local Traefik instance at **https://carnet-spots-api.dev.localhost**.

**Running tests**: the test suite runs against a real MySQL database (a separate `testing` database on the same Sail `mysql` service — see `phpunit.xml`), not SQLite. This means `./vendor/bin/sail pest` / `./vendor/bin/sail artisan test` must run **inside the Sail container** (`DB_HOST=mysql` only resolves there). The `testing` database is created automatically by `vendor/laravel/sail/database/mysql/create-testing-database.sh` on first container start — but only when the `carnet-spots-api-mysql` volume is empty. If tests fail with "Unknown database 'testing'" on an existing volume, create it manually (`./vendor/bin/sail mysql -e "CREATE DATABASE testing"`) or reset the volume with `./vendor/bin/sail down -v`.

## 10. Environments

Three environments, mirroring a standard branch-per-environment workflow:

| Environment | Branch    | Where it runs                          | Database                |
| ----------- | --------- | --------------------------------------- | ------------------------ |
| Development | `dev`     | Local (Sail + Traefik, see above)       | MySQL (Sail `mysql` service) |
| Staging     | `staging` | Coolify                                 | MySQL (Coolify-managed)  |
| Production  | `prod`    | Coolify                                 | MySQL (Coolify-managed)  |

MySQL everywhere: unlike SQLite (a single file with no network protocol), MySQL supports remote client access (TablePlus, DBeaver, etc.) over a secure channel — needed to inspect live data — and avoids the single-writer bottleneck of a file-based database if the app ever opens up beyond solo use. Keeping dev on the same engine as staging/production also avoids SQLite-vs-MySQL behavior drift (e.g. migrations, strictness) between environments.

Staging and production are built from the root `Dockerfile` (a self-contained PHP-FPM + Nginx + Supervisor image — separate from `compose.yaml`, which is Sail's local dev setup only). Coolify builds and deploys it per branch, injecting environment variables and running the container's `docker/entrypoint.sh` (`config:cache`, `route:cache`, `migrate --force`) on every deploy. No `.env` file is baked into the image — all configuration comes from environment variables set in Coolify per app.

**Connecting to staging/production data from a local client:** never expose MySQL's port (3306) directly to the internet. Use Coolify's built-in secure database proxy, or an SSH tunnel to the server, and point a local GUI client (TablePlus, DBeaver, ...) at `localhost` through that tunnel.
