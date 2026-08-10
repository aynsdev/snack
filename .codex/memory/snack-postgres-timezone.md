---
name: snack-postgres-timezone
description: "Snack's timestamptz columns skew whenever the pgsql session timezone and config('app.timezone') disagree — resolved 2026-08-03 by deriving one from the other; the failure mode is a stored time reading back in the future."
metadata:
  node_type: memory
  type: project
  originSessionId: ab4c6e02-468d-4508-bfd9-0947137b8fce
  modified: 2026-08-03T02:25:19.570Z
---

## The invariant

**`config('database.connections.pgsql.timezone')` must equal `config('app.timezone')`.** Snack's tables are `timestamptz` throughout and Laravel writes a *naive* datetime string, so PHP's zone decides what the string means and Postgres's session zone decides how it is read. Any disagreement between the two is a silent offset on every write.

Settled 2026-08-03 as `'timezone' => env('DB_TIMEZONE') ?: config('app.timezone')`. Calling `config()` inside `config/database.php` does work — `app.php` loads first — and it removes the chance of the two drifting apart again. Do not "simplify" it back to a hardcoded literal.

## Why it hides

The round trip is **self-consistent in wall-clock digits**. Anything comparing stored timestamps *to each other* — ordering, ranges, the unread pointers — behaves perfectly, and Snack orders by monotonic ids nearly everywhere.

It breaks only where stored time meets PHP's `now()`. **How to spot it:** dump both sides as `toIso8601String()`. Matching digits with different offsets is the signature.

## Two rounds of this

1. **Phase 6 (2026-07-31).** No `timezone` key at all, so the session fell back to the DB server's local zone. Pinned to UTC, which was right *while* `app.timezone` was UTC.
2. **2026-08-03.** Commit a827cd6 moved `config/app.php` to `Asia/Manila` and left the pinned UTC behind, re-opening the same 8-hour gap in the opposite direction.

Round 2's symptom was worse than round 1's: `last_seen_at` read back **eight hours in the future**, so every `stored < now() - window` test was permanently false. That silently disabled the presence sweep and meant no digest email could ever be sent — see [[snack-presence]].

## What to apply

- The risky shape is any new comparison of a stored `timestamptz` against `now()` or a PHP-computed instant. Known sites: the presence sweep, `NotificationDelivery::isAway()`, invitation expiry, the message edit window.
- Tests that set an absolute clock must set it in the *subject's* zone, not the app's — `now()->setTimezone('UTC')->setTime(8, 30)`, never `now()->setTime(8, 30)`. A test written the second way passes only while `app.timezone` happens to be UTC and starts failing the day someone changes it, pointing at the wrong code.
- **Rows written before each fix keep the old skew and were never migrated.** Old dev/production rows read back offset; `RefreshDatabase` hides this from the suite entirely.
- Same class of silence as [[snack-local-serving]]: nothing errors, nothing logs, the feature quietly decides the wrong thing.

See [[snack-phase-6-decisions]] and [[snack-test-and-gate-traps]].
