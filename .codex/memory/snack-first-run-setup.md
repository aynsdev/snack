---
name: snack-first-run-setup
description: "Snack's invite-only registration was already built and made a fresh install a dead end; first-run setup at /setup is the only unauthenticated account-creation path and is gated on the users table being empty."
metadata: 
  node_type: memory
  type: project
  originSessionId: ab4c6e02-468d-4508-bfd9-0947137b8fce
  modified: 2026-08-01T07:17:10.691Z
---

Built 2026-07-31, after Phase 6. Landing page, login restyle, and first-run setup.

## Three calls the user made explicitly — do not re-litigate

Asked directly, and answered:

1. **Onboarding is a first-run install wizard**, not per-workspace onboarding for every signup. One-shot, instance-level. The existing two-field `workspace/create.tsx` was left alone — its comment explicitly rejects a wizard ("the first thing a new team sees is a working #general rather than a setup wizard"), and that still governs *later* workspaces. Setup is the exception because there is no instance yet, not a reversal.
2. **Invite-only after setup** — confirmed as the target state; turned out to already be the shipped default.
3. **The landing page is a product marketing page**, not a minimal sign-in door. This is a taste call and is *not* recoverable from the code — a future session looking at a six-feature marketing page on an invite-only internal tool might reasonably think it is over-built. It was asked for.

## The premise that was already true

**Invitation-only registration was fully built before this work started** — `RegistrationGate`, a `Fortify::registerView` that 404s, and `CreateNewUser` enforcing the same rule on POST, with `snack.allow_public_registration` defaulting to **false** and 8 passing tests. The brief asked for it; the answer was "already done, zero lines needed." Same shape as Phase 6's wrong premise about channel notification levels — see [[snack-phase-status]]. **Check before building; this codebase is further along than its briefs assume.**

## The bug that fell out of it

Closed registration plus an empty database is a **dead end**: `/register` 404s because nobody holds an invitation, `/login` has no account to authenticate, and the only way in was `tinker` or `SnackHqSeeder`. Nobody noticed because the dev database has always had users.

## The load-bearing rule

`InstanceSetup::isPending()` is `! User::query()->exists()` — **not** a config flag and **not** a lock file. That is the one signal which cannot be forged, cannot drift, and cannot be re-entered once false. A flag left switched on after the first account exists would reopen unauthenticated account creation on a live install.

Guards, in order of paranoia: `EnsureInstanceNeedsSetup` on both routes (404s once any user exists) → `CompleteSetupRequest::authorize()` repeats it → the action re-checks **inside the transaction** with `lockForUpdate()`, so two simultaneous submissions on a fresh box cannot both produce an owner.

Deliberately **not memoized**: setup flips this state mid-request, and a cached answer would let the middleware and the controller disagree within one request.

## Two design calls worth keeping

- **Invitations are issued outside the transaction and are non-fatal.** Setup can never be re-run, so an unreachable SMTP or queue backend must not roll back the account and strand the install with no way in — the exact failure the screen exists to prevent. Failed addresses come back in the return value and get named in the flash message rather than swallowed.
- **The admin is `markEmailAsVerified()`'d immediately.** Nobody exists who could action a verification email, and every route sits behind `verified` middleware, so skipping this locks the owner out of the app they just installed.

## What this broke, and why the fix is not a workaround

Adding the redirects to `/` and the login view broke exactly two tests — `ExampleTest` and `AuthenticationTest::login screen can be rendered` — both of which assumed an empty database still renders those pages. Both now create a user first. **Any future test that hits `/` or `/login` without a user will now get a 302, not a 200.**

The login page no longer links to `/register` (it was a dead end for anyone without an invitation); it says Snack is invite-only instead. The landing page has no sign-up CTA for the same reason.

`auth-card-layout.tsx` and `auth-split-layout.tsx` are unused starter-kit leftovers still carrying `AppLogoIcon`; only `auth-simple-layout` is wired up and only it was rebranded.

See [[snack-phase-status]] and [[snack-test-and-gate-traps]].
