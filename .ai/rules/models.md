---
paths:
  - app/Models/Point.php
  - app/Models/Tag.php
---

# Models

## scopeNearby pre-filters with an exact spherical bounding box — keep it
`Point::scopeNearby()` adds a `whereBetween` on latitude/longitude before the Haversine `HAVING`, so `radius` actually reduces the rows the distance is computed for. With the composite index `(owner_id, latitude, longitude)`, MySQL can seek on `owner_id` + the latitude range; the longitude range is only checked on the rows inside that latitude band (an index can't range-seek two columns at once), not used to seek. The box is the exact one enclosing the circle on a sphere (`latitudeDelta = rad2deg(r / R)`, `longitudeDelta = rad2deg(asin(sin(r / R) / cos(lat)))`), not a flat-Earth `km / (111 × cos(lat))` approximation, which cuts off points at the edge of large radii at high latitudes.

The longitude filter is dropped in two cases where a single range can't cover the circle: when the circle contains a pole (`abs(lat) + latitudeDelta >= 90`, which is always true once the radius exceeds a quarter of the Earth, ~10 000 km — `radius` goes up to 20 000 km) and when the box crosses the ±180° meridian. Each case has a regression test in `tests/Feature/Api/NearbyPointsTest.php` (pole, 180th meridian, 80°/200 km edge point, Paris → Sydney) — run them after any change to the scope.

## visited_at is converted to the app timezone on write
The `visitedAt()` set mutator parses the value and converts it to `config('app.timezone')` (UTC) before storing. Without it, Laravel keeps the wall-clock time of a value sent with an offset (`2026-07-14T21:00:00+02:00` would be stored as 21:00 instead of 19:00). It lives on the model, not in a Form Request, so it also applies to seeders and the future sync/replay endpoints.

The column is a `datetime`, not a `timestamp` (migration `2026_10_26_012305_change_visited_at_to_datetime_on_points_table`): a MySQL `timestamp` only holds 1970 → 2038 and a date outside that range made the insert fail with a 500. `datetime` does no timezone conversion, which is fine because the mutator always writes UTC. Don't switch it back to `timestamp`.

That migration must not force the MySQL session timezone (`SET time_zone = …`): existing `timestamp` values were written through the server's default session timezone, and converting them under that same timezone is what keeps every visit time unchanged, whatever the server is set to (forcing UTC would shift them by 1–2 h on a server set to e.g. `Europe/Paris`).

## Tag name uniqueness lives on the generated active_name column
Tags are soft-deleted, so `unique(owner_id, name)` would block reusing a deleted tag's name forever. The DB unique index is on `(owner_id, active_name)`, where `active_name` is a stored generated column `if(deleted_at is null, name, null)` (MySQL allows many NULLs in a unique index). Never write `active_name` yourself, and keep `->withoutTrashed()` on every `Rule::unique('tags', 'name')` so validation matches the index.
