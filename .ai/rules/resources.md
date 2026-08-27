---
paths:
  - 'app/Http/Resources/**'
---

# Resources

## Cast Point latitude/longitude to float in API Resources
`Point::latitude`/`longitude` are cast `decimal:7` on the model, which serializes as a string (e.g. `"48.8566140"`). Cast to `(float)` in the API Resource before returning JSON so web/mobile clients receive numbers, not strings — decide this once here rather than each client defensively parsing.
