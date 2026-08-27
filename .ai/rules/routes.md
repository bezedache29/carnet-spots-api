---
paths:
  - routes/api.php
---

# Routes

## Gate business routes behind auth:sanctum + verified
Email verification is enforced from V1 (User implements MustVerifyEmail). Once points/tags CRUD routes exist, wrap them in `Route::middleware(['auth:sanctum', 'verified'])`, not just `auth:sanctum`. `/user`, `/logout`, and the verification routes themselves stay auth:sanctum-only (or public) — `verified` is only for business/data endpoints, not account-management ones.
