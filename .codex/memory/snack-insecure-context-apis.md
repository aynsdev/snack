---
name: snack-insecure-context-apis
description: "snack.test is served over plain HTTP, so secure-context-only browser APIs fail there — crypto.randomUUID is undefined, and Notification exists but is permanently denied."
metadata: 
  node_type: memory
  type: project
  originSessionId: b74f986f-b401-42e2-aa83-3ee69b4d9a3d
  modified: 2026-08-03T23:55:27.499Z
---

Snack is deliberately served over plain HTTP at `snack.test` so Reverb's `ws://` works (see [[snack-local-serving]]). That origin is **not a secure context**: `window.isSecureContext === false`, and every secure-context-only browser API is `undefined` there — `crypto.randomUUID`, `crypto.subtle`, and friends. Only `https://` and the literal `localhost` are exempt; a custom `.test` domain is not.

This caused a silent, total message-loss bug found on 2026-07-30: `useMessageTimeline.send()` minted its optimistic-send nonce with `crypto.randomUUID()` **before** its try/catch, so the `TypeError` escaped — no POST was made, no pending message was recorded, and `MessageComposer` cleared the textarea anyway (it clears as soon as it hands the text to `onSend`). Every message typed in the browser vanished with nothing on screen to say so, in channels *and* DMs. Fixed by `resources/js/lib/nonce.ts` (`createNonce()`), which falls back to `crypto.getRandomValues` — that one has no secure-context restriction.

## The Notification API — the same trap, but it lies to feature-detects

Measured on the real origin, 2026-07-30:

```
window.isSecureContext        false
typeof window.Notification    "function"   ← the feature-detect PASSES
Notification.permission       "denied"     ← permanently, before anyone is asked
'serviceWorker' in navigator  false
```

Unlike `crypto.randomUUID`, the constructor is *present*, so `if ('Notification' in window)` sails through and `requestPermission()` resolves `"denied"` without ever showing a prompt. Read naively that says "the user blocked notifications", which would send them into Chrome's site settings to fix something no setting can fix. `lib/notifications.ts` therefore checks `isSecureContext` **before** reading `permission` and reports `insecure-context` as its own state; the sidebar's "Get notified" prompt renders nothing in that state rather than offering a dead button.

Chrome also treats a *dismissed* permission prompt as a permanent denial, so the ask is behind an explicit affordance — never requested on load, when there is nothing to notify about yet and the answer is usually no.

**Verifying notifications locally,** since the origin can never grant them: patch the environment in the page before triggering an arrival — `Object.defineProperty(window, 'isSecureContext', {value: true})`, a recording stub class as `window.Notification` with `static permission = 'granted'`, and `document.hasFocus = () => false`. The shipped code then runs unmodified end to end and the recorded constructor arguments are the assertion. This confirmed all four cases (own message, unattended arrival, attended arrival, cross-container DM).

## getUserMedia — the same trap, and it takes a whole feature with it

`navigator.mediaDevices` is **undefined** on `snack.test`. Confirmed in the browser 2026-08-04: clicking Huddle on the real origin cannot reach a microphone at all, so [[snack-huddles]] is inert there by browser policy, not by any bug. `use-huddle.tsx` checks `canCaptureMedia()` **before** the join request and surfaces "Huddles need a secure connection…" rather than letting a `TypeError` out of `setMicrophoneEnabled()` — which would read as the feature being broken.

To exercise huddles end to end locally, serve the app on **`localhost`** (`php artisan serve --host=127.0.0.1 --port=8123`), which browsers exempt as a secure context. Note the session cookie is per-host, so that origin needs its own sign-in. Reverb still works from there because `ws://` is fine from a plain-HTTP page.

**Why:** the whole class of bug is invisible to the test suite, because jsdom and Node both provide `crypto.randomUUID` unconditionally and neither provides `Notification` or `mediaDevices` at all. Only a real browser on the real origin reproduces it.

**How to apply:** before using any `crypto.*`, clipboard, notification, or media browser API, check whether it is secure-context-only and guard it with a fallback. A global *existing* proves nothing — check `isSecureContext` first, and treat a permission value read on an insecure origin as meaningless. Never mint a value that can throw *before* the try/catch that is supposed to protect the operation.

See [[snack-container-abstraction]] and [[snack-phase-status]].
