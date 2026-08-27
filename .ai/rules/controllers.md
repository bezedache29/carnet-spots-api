---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Scope tag_ids by owner when attaching to a point
The `point_tag` pivot has no `owner_id` — nothing at the DB level stops attaching a tag owned by another user to your point. When accepting `tag_ids` input (store/update on points), validate with `Rule::exists('tags', 'id')->where('owner_id', Auth::id())` so a point can never end up tagged with another user's tag.

## Use sync()/syncWithoutDetaching(), never attach(), on replayable endpoints
The `point_tag` pivot has a composite primary key (point_id, tag_id) with no upsert semantics. The mobile client replays queued offline mutations, so the same "attach tag X to point Y" call can arrive twice. Plain `attach()` throws a duplicate-key exception on replay — use `sync()` or `syncWithoutDetaching()` on any endpoint that mutates point/tag relations so replays are idempotent.
