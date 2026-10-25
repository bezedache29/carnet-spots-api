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
    $table->enum('status', ['a_tester', 'valide', 'a_eviter', 'deja_dormi'])
          ->default('a_tester');
    $table->timestamp('visited_at')->nullable();
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

- **Downward sync**: endpoint(s) letting the mobile client fetch the relevant points (road-trip area) before departure.
- **Mutation replay**: the mobile client queues offline changes (status, note) locally; this API must expose the endpoints needed to replay them once network is regained (likely: standard CRUD endpoints on `points`, with timestamp-based conflict resolution — a *last-write-wins* strategy is planned given the solo use case, to be documented precisely before implementation).

## 6. V1 roadmap for this repo

1. Migrations + Eloquent models (`Point`, `Tag`, `User`)
2. API routes: CRUD `points`, CRUD `tags`, "points near a position" endpoint (Haversine)
3. Sanctum auth (even solo, a single account)
4. Sync endpoint(s) for mobile (batch fetch by area) + mutation replay endpoints
5. Static file service for regional `.pmtiles`

## 7. Out of scope for V1

- Multi-user registration, cross-user access policies, moderation of public points
- Landing page / blog (will be a separate 4th repo, Next.js, if the need is confirmed — independent of this API but a consumer of it)
- Photos attached to points
- Notifications ("point nearby")

## 8. Current implementation status

Roadmap steps 1 (domain layer), 2 (CRUD + nearby search) and 3 (Sanctum authentication) are done. Next up is step 4 (sync + mutation replay for the mobile client).

**Done:**

- Laravel 13 skeleton, PHP 8.3, Pest 4 (functional syntax, `RefreshDatabase` on feature tests)
- Laravel Boost installed (AI-agent guidelines, skills, MCP server for this codebase)
- Two project-specific AI agents committed to the repo (`.claude/agents/`):
  - `pest-tester` — writes and runs Pest tests following this project's domain (ownership scoping, geo search, sync/replay)
  - `laravel-code-reviewer` — reviews security/ownership, correctness, performance, and Laravel conventions before merge
- Shared project rules for AI agents and teammates in `.ai/rules/` (tag ownership scoping, idempotent pivot writes, float lat/lng in API Resources)
- Local dev environment: Laravel Sail (Docker), reverse-proxied through a local Traefik instance, MySQL as the local database (Sail's `mysql` service), phpMyAdmin for browsing it
- Test suite runs against MySQL (separate `testing` database), same engine as every environment
- Production `Dockerfile` (PHP-FPM + Nginx + Supervisor) for staging/production on Coolify, MySQL-backed
- Sanctum and Scramble packages installed (not wired to any route yet)
- **Roadmap step 1** — domain layer:
  - `points`, `tags` and `point_tag` migrations (cascade deletes on owner and pivot, `(owner_id, latitude, longitude)` index, unique tag name per owner)
  - `PointStatus` / `PointVisibility` backed enums, used by both the migration and the model casts
  - `Point` and `Tag` Eloquent models with their relations, factories, and feature tests (`tests/Feature/Models/`)
- **Roadmap step 2** — versioned REST endpoints under `/api/v1` (see the table below):
  - CRUD `points` (paginated list with a stable order — `created_at` then `id` — so no point is skipped or repeated across pages, partial `PATCH` updates, tags set through `tag_ids` with `sync()` so replays are idempotent)
  - `visited_at` is always stored in the app timezone (UTC): a value sent with an offset (`2026-07-14T21:00:00+02:00`) is converted, not truncated
  - CRUD `tags` (name unique per owner)
  - Nearby search: Haversine distance in SQL, pre-filtered by the exact spherical bounding box of the search circle so the `(owner_id, latitude, longitude)` index is used; correct at any latitude, including searches that contain a pole or cross the 180th meridian
  - Ownership enforced by `PointPolicy` / `TagPolicy`: another user's point or tag answers **404**, not 403, so its existence is not leaked
  - Form Requests for all input, API Resources for all output (`latitude` / `longitude` returned as numbers)
- **Roadmap step 3** — Sanctum token authentication: login (one token per device: logging in again from the same `device_name` revokes that device's previous token; throttled to 5 attempts/minute), logout (revokes the current device's token only), current user
- Feature tests for every endpoint in `tests/Feature/Api/V1/` (401 / 404 cross-owner / 422 validation / happy path)

**API endpoints** (all JSON; every route except `login` needs an `Authorization: Bearer <token>` header):

| Method      | URL                       | Purpose                                                                 |
| ----------- | ------------------------- | ----------------------------------------------------------------------- |
| `POST`      | `/api/v1/login`           | `email`, `password`, `device_name` → `{ token, user }`                  |
| `POST`      | `/api/v1/logout`          | Revoke the token used for this request                                  |
| `GET`       | `/api/v1/user`            | Current user                                                            |
| `GET`       | `/api/v1/points`          | User's points, newest first (ties broken by `id`), 50 per page, with tags |
| `POST`      | `/api/v1/points`          | Create a point (`latitude`, `longitude` required; optional `tag_ids`)   |
| `GET`       | `/api/v1/points/nearby`   | `latitude`, `longitude`, `radius_km` (default 10, max 200), `limit` (default 50, max 200) → nearest first, with `distance_km` |
| `GET`       | `/api/v1/points/{id}`     | One point with its tags                                                 |
| `PATCH`     | `/api/v1/points/{id}`     | Update only the fields sent; `tag_ids` replaces the tags when present   |
| `DELETE`    | `/api/v1/points/{id}`     | Delete a point (its tags are kept)                                      |
| `GET`       | `/api/v1/tags`            | User's tags, alphabetical                                               |
| `POST`      | `/api/v1/tags`            | Create a tag (`name`)                                                   |
| `GET`       | `/api/v1/tags/{id}`       | One tag                                                                 |
| `PATCH`     | `/api/v1/tags/{id}`       | Rename a tag                                                            |
| `DELETE`    | `/api/v1/tags/{id}`       | Delete a tag (detached from its points, points are kept)                |

Interactive documentation of these endpoints is generated by Scramble at `/docs/api` (local environment).

There is no sign-up endpoint (solo use in V1, see section 7). Create the account once with Tinker:

```bash
./vendor/bin/sail artisan tinker --execute 'App\Models\User::create(["name" => "Christophe", "email" => "me@example.com", "password" => "change-me"]);'
```

**Not started yet:**

- Sync and mutation-replay endpoints — roadmap step 4

**Open points to decide later** (raised in code review):

- Replaying a queued `POST /points` creates a duplicate, and a replayed `DELETE` answers 404. To settle before step 4 — e.g. a client-generated UUID per point (`client_uuid`, unique per owner) and treating 404 on `DELETE` as success on the mobile side.
- `bootstrap/app.php` trusts `X-Forwarded-For` from any proxy (`trustProxies(at: '*')`). If the app is reachable without going through Traefik/Coolify, a client could spoof its IP and bypass the login throttle. To check against the Coolify setup.
- `.pmtiles` static file serving — roadmap step 5

## 9. Local development

Requires Docker.

On a fresh clone, `vendor/` does not exist yet (so `./vendor/bin/sail` doesn't either). Install the locked PHP dependencies once through Docker, without needing PHP on the host:

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
| Development | `develop` | Local (Sail + Traefik, see above)       | MySQL (Sail `mysql` service) |
| Staging     | `staging` | Coolify                                 | MySQL (Coolify-managed)  |
| Production  | `main`    | Coolify                                 | MySQL (Coolify-managed)  |

MySQL everywhere: unlike SQLite (a single file with no network protocol), MySQL supports remote client access (TablePlus, DBeaver, etc.) over a secure channel — needed to inspect live data — and avoids the single-writer bottleneck of a file-based database if the app ever opens up beyond solo use. Keeping dev on the same engine as staging/production also avoids SQLite-vs-MySQL behavior drift (e.g. migrations, strictness) between environments.

Staging and production are built from the root `Dockerfile` (a self-contained PHP-FPM + Nginx + Supervisor image — separate from `compose.yaml`, which is Sail's local dev setup only). Coolify builds and deploys it per branch, injecting environment variables and running the container's `docker/entrypoint.sh` (`config:cache`, `route:cache`, `migrate --force`) on every deploy. No `.env` file is baked into the image — all configuration comes from environment variables set in Coolify per app.

**Connecting to staging/production data from a local client:** never expose MySQL's port (3306) directly to the internet. Use Coolify's built-in secure database proxy, or an SSH tunnel to the server, and point a local GUI client (TablePlus, DBeaver, ...) at `localhost` through that tunnel.
