---
paths:
  - app/Models/Point.php
---

# Models

## scopeNearby has no bounding-box prefilter — full owner scan per call
`Point::scopeNearby()` filters distance via `HAVING` after computing Haversine for every row, so the composite index `(owner_id, latitude, longitude)` only helps on `owner_id` — `latitude`/`longitude` are never used as index predicates. `radius` never reduces the work done, only what's returned after the fact.

Fine today: solo usage, low row count, and `radius` has no product-level cap (only a technical `max:20000` safety net — see `NearbyPointsRequest`). Revisit once usage grows or multi-user opens up ([[project_point_model_decisions]]): add a `whereBetween` bounding box on lat/lng computed from `radius` before the `having`, to actually use the index. Doing this correctly requires handling antimeridian wraparound (a naive bbox breaks near ±180° longitude) — not needed for France-only usage today, but don't copy-paste the naive version if this ever goes global.
