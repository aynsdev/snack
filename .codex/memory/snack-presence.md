---
name: snack-presence
description: "presence_status was read in three places and written by nothing for seven phases — the green dot, @here, and every notification email all depended on a column stuck at its default."
metadata: 
  node_type: memory
  type: project
  modified: 2026-08-03T02:25:36.288Z
  originSessionId: 1d00895f-0619-4f28-9389-8fe7d66503a7
---

Built 2026-08-03, after the user reported "person status is offline always" in production.

## The bug

`users.presence_status` and `users.last_seen_at` were **read by three features and written by nothing**. Every real account sat at the column default of `Offline` forever. Local dev looked fine only because `UserFactory` and `SnackHqSeeder` set the values directly.

The dot was the least of it. Because an `Offline` user is by definition away:

- `NotificationDelivery::shouldMail()` returned true for everyone, so **every notification was also emailed**.
- `@here` filters on `presence_status = online`, so it **expanded to nobody**.

`PresenceStatus`'s own docblock claimed the values were "derived from live Reverb presence channels and Redis heartbeats", and `config/snack.php` referenced a `presence.away_after_seconds` key that did not exist. Documented intent, no implementation — worth distrusting docblocks that describe a mechanism rather than the code in front of them.

## The design, and why

- **HTTP heartbeat, not the Reverb presence channel.** Deriving presence from socket membership makes it *invert* when the socket layer is down: everyone shows offline, `@here` dies, and every notification gets emailed — presence breaks precisely when the app is already broken, and gives no independent signal. A plain `POST /presence` rides the same path as every other request. This mattered here: the socket layer *was* down in production when the bug was reported.
- **Presence is a claim with an expiry, never a flag.** Browsers do not say goodbye — a crash, a closed lid, a dead network all send nothing. Anything that can be set but never decays is wrong by construction. `snack:sweep-presence` runs every minute: Online → Away at 150s, anything → Offline at 300s.
- **The client may only claim Online or Away** (`HeartbeatRequest` restricts the enum). Offline is the *absence* of beats; DoNotDisturb is a deliberate setting a background timer must never assert for someone.
- The sweep must call `RecordPresence::expire()`, **never `heartbeat()`** — stamping `last_seen_at` on a demotion restarts the clock the next stage measures against and strands people at Away forever. Covered by a named regression test.

## Traps hit while building it

- The sweep is invisibly dead if [[snack-postgres-timezone]]'s invariant is broken: `last_seen_at` read back in the future makes `last_seen_at < now() - window` permanently false. The timezone fix was a prerequisite, not a side quest.
- Any new broadcast event must be added to the `Event::fake([...])` list in `tests/Pest.php`, or the sync queue makes a real HTTP call to a Reverb server that is not running and every test 500s.

Related: [[snack-test-and-gate-traps]], [[snack-spec-constraints]].
