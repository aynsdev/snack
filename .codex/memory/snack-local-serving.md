---
name: snack-local-serving
description: "How to run and browser-verify Snack locally: Herd serves snack.test over plain HTTP, Reverb plus a queue worker must be up, and every failure mode here is silent."
metadata: 
  node_type: memory
  type: project
  originSessionId: 93966f66-7a4d-4ea9-ad1c-7b8b9437d108
  modified: 2026-08-01T13:46:00.291Z
---

Local setup, decided with the user on 2026-07-29 and extended while browser-verifying Phase 3 on 2026-07-30.

## Serving

- **Herd's nginx serves `snack.test` on port 80, always** — independent of anything the user runs. `composer run dev` supplies only Vite (assets), `reverb:start`, `queue:listen`, and `pail`. `DevCommands::except('server')` removes the default `artisan serve`; nothing listens on :8000 and nothing needs to. **Never run a command to serve the site.**
- The site is deliberately **unsecured** (`herd unsecure snack.test`, `APP_URL=http://snack.test`). The user chose plain HTTP over the alternative (`herd secure` + a `herd proxy reverb.snack.test ... --secure`) because pusher-js computes `useTLS` as "page is https **or** forceTLS !== false" — an HTTPS page always dials `wss://` and silently hangs at "unavailable" against a plain-`ws` Reverb, with no error anywhere. The cost of that choice is [[snack-insecure-context-apis]].
- **Known blocker if login 419s:** cookies stored as `Secure` during the earlier HTTPS era cannot be overwritten by the HTTP origin (Chrome's Strict Secure Cookies). `SESSION_SECURE_COOKIE=false` now prevents recurrence, but stale `snack.test` cookies must be cleared once by the user in Chrome — that is theirs to do, not mine.

## What real-time actually needs running

Verifying anything real-time needs **both** Reverb and a queue worker: message events are queued broadcasts (`QUEUE_CONNECTION=redis`), so with no worker they simply never arrive and nothing errors anywhere.

- **Docker squats `[::1]:8080`** with a dead listener. `0.0.0.0:8080` is still bindable, so `reverb:start --host=0.0.0.0 --port=8080` works and Chrome resolved `localhost` to IPv4 in practice — but if the socket ever looks connected-yet-silent, that IPv6 listener is the first suspect, not the app.
- Redis is shared with the user's other projects, whose workers also listen on `default`. Snack's cache prefix is `snack-database-`, so **no job stealing** — but a worker still has to be started for Snack itself.
- **Queue workers hold stale class definitions.** After editing an event class, a long-running worker keeps deserializing into the *old* signature and jobs fail with a confusing `TypeError: Cannot assign string to property … of type int`. Restart the worker before concluding the code is wrong; this cost real time on 2026-07-30.

## Browser-verification traps

- **Chrome serves a cached bundle after `npm run build`.** Content-hashed chunks change on disk while the tab keeps executing the old ones — the console kept reporting a bug that had already been fixed. Hard-reload (`cmd+shift+r`) after every rebuild before trusting what you see.
- **`npm run build` is usually not what the browser is running.** If `composer run dev` is up, Vite's dev server owns the page and Laravel serves modules from `http://[::1]:5173` — so `npm run build` output and browser behaviour come from two different places, and rebuilding "to test a fix" changes nothing while the dev server has already hot-applied the edit. Check `[...document.querySelectorAll('script')].map(s => s.src)` before drawing conclusions: `/build/assets/…` means the build, `:5173` means the dev server. This cost real time on 2026-08-01, and it makes bundle-size measurements and rendering behaviour non-comparable.
- **Run the control before blaming your own change.** A blank page was nearly attributed to a stale cache; `git stash` of just the suspect files, reload, and compare settles it in a minute and is the difference between a real revert and a wrong one.
- Screenshot pixel coordinates do **not** map 1:1 to page coordinates (the viewport was 1720×1212 behind a ~1480px-wide screenshot), so clicking by eyeballed coordinates misses. Focus elements via a DOM query, or read the element's real rect first. Several "the app didn't send my message" observations were really clicks landing on nothing.
- **Drive one interaction per `javascript_tool` call, start to finish.** Splitting a flow across two calls lets focus drop between them, and anything with `onBlur` resets. This produced a confident, wrong "Enter sends instead of choosing from the mention picker" on 2026-07-31; run in a single call it worked correctly. Element refs from `find` also go stale across calls as the DOM re-renders.
- **Radix triggers respond to `pointerdown`, not a synthetic `.click()`.** Dispatch `PointerEvent('pointerdown')` + `pointerup` + `click` to open a dropdown from script, or it silently does nothing.

**Why:** every failure mode here is silent — a dead socket, an unstarted worker, a stale worker, a cached bundle, and a missed click all look identical to "the feature is broken".

**How to apply:** before debugging a real-time feature, confirm Reverb is up, a *freshly started* worker is up, and the tab is running the current bundle. Settle data-layer questions against the database or a test rather than against the UI.

See [[snack-phase-status]] and [[snack-insecure-context-apis]].
