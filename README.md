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
    $table->foreignId('owner_id')->constrained('users');
    $table->string('title')->nullable();
    $table->decimal('latitude', 10, 7);
    $table->decimal('longitude', 10, 7);
    $table->text('note')->nullable();
    $table->enum('status', ['a_tester', 'valide', 'a_eviter', 'deja_dormi'])
          ->default('a_tester');
    $table->timestamp('visited_at')->nullable();
    $table->enum('visibility', ['private', 'public'])->default('private');
    $table->timestamps();

    $table->index(['latitude', 'longitude']);
});
```

### `tags`

```php
Schema::create('tags', function (Blueprint $table) {
    $table->id();
    $table->foreignId('owner_id')->constrained('users');
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

The API is at the bootstrap stage — infrastructure is in place, domain features are not yet implemented.

**Done:**

- Laravel 13 skeleton, PHP 8.3, Pest 4 (functional syntax, `RefreshDatabase` on feature tests)
- Laravel Boost installed (AI-agent guidelines, skills, MCP server for this codebase)
- Two project-specific AI agents committed to the repo (`.claude/agents/`):
  - `pest-tester` — writes and runs Pest tests following this project's domain (ownership scoping, geo search, sync/replay)
  - `laravel-code-reviewer` — reviews security/ownership, correctness, performance, and Laravel conventions before merge
- Local dev environment: Laravel Sail (Docker), reverse-proxied through a local Traefik instance, MySQL as the local database (Sail's `mysql` service)

**Not started yet:**

- `points` / `tags` / `point_tag` migrations and Eloquent models
- API routes and controllers (CRUD, nearby search)
- Sanctum authentication
- Sync and mutation-replay endpoints
- `.pmtiles` static file serving

## 9. Local development

Requires Docker.

```bash
# Start the app (builds the image on first run)
./vendor/bin/sail up -d

# Run the test suite
./vendor/bin/sail pest

# Artisan commands
./vendor/bin/sail artisan migrate
```

The app is reverse-proxied through a local Traefik instance at **https://carnet-spots-api.dev.localhost**.

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
