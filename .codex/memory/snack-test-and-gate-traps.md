---
name: snack-test-and-gate-traps
description: "How Snack's test suite is wired and the four toolchain rejections that are non-obvious — unit tests do not boot Laravel, factories bypass the actions, and three static-analysis traps."
metadata: 
  node_type: memory
  type: project
  originSessionId: 5d860785-d295-43ee-8e78-483fe9170fcd
  modified: 2026-08-10T02:22:28.511Z
---

Things about Snack's own test and gate setup that are invisible until they block you. All four cost real time during Phase 5.

**`tests/Unit` does not boot Laravel.** `tests/Pest.php` applies `TestCase` + `RefreshDatabase` with `->in('Feature')` only, so a unit test is plain PHPUnit with no container. Any `config()`, `app()`, or facade call there dies with `Target class [config] does not exist` — a message that reads like a broken install rather than the real cause. This is *why* `SearchQuery::MIN_TERM_LENGTH` is a class constant instead of config: a value object that reaches into the container cannot be unit-tested. Prefer the same fix (constant, or inject the value) over relocating the test to `Feature`.

**Factories write straight to the table and bypass the actions.** `makeMessage()` / `makeConversationMessage()` in `Pest.php` call `Message::factory()`, so nothing an action would have done happens — no queued jobs, no denormalized bookkeeping. Anything an action derives has to be built explicitly in the test. Phase 5's search tests call a local `reindex()` helper for exactly this reason, and there is a separate test asserting the *send endpoint* indexes on its own, so the helper cannot hide a broken pipeline. The queue is `sync` under test (`phpunit.xml`), so a job dispatched through a real endpoint runs inline.

**Three static-analysis rejections worth knowing before writing the code:**

- Adding `file()` or `image()` to a `FormRequest` **collides with `Illuminate\Http\Request`'s own methods** — different signature and return type, so PHPStan fails on covariance. Name the accessor for the thing (`upload()`, `avatar()`), not the type.
- `Illuminate\Support\CarbonImmutable` **does not exist**. It is `Carbon\CarbonImmutable`. `Illuminate\Support\Carbon` does exist, which makes the wrong import look right.
- Assigning to `ref.current` during render fails the project's `react-hooks/refs` ESLint rule. When a hook needs a synchronously-current mirror of state (e.g. `useUploads`, where several upload callbacks read the tray between renders), write the ref **inside the mutator alongside `setState`** — a small `commit(fn)` helper — never at the top level of the hook body.

**Never run `php artisan wayfinder:generate` bare.** `vite.config.ts` configures the plugin with `formVariants: true`, so the Vite dev server generates *with* `--with-form`. Regenerating by hand without the flag silently strips `.form()` off every route helper, and `tsc` then reports ~14 errors in files nobody touched — auth pages, settings pages — which reads like a broken checkout. Always `php artisan wayfinder:generate --with-form`, or just let Vite do it.

**Laravel's notification broadcast needs faking too.** `tests/Pest.php` fakes Snack's own broadcast events so the sync queue does not make a real HTTP call to Reverb. `Illuminate\Notifications\Events\BroadcastNotificationCreated` is a *separate* class and needed adding to that list in Phase 6 — without it, every test that sends a message 500s on "No matching application for ID". Faking it leaves the database channel untouched, so a test can still assert the notification was **stored** while `Event::assertDispatched()` covers the live half.

**A caught unique-constraint violation still poisons the surrounding transaction.** PostgreSQL aborts the *whole* transaction on a constraint violation and refuses every later statement with `current transaction is aborted, commands ignored until end of transaction block` — so `try { insert } catch (UniqueConstraintViolationException) { re-read }` is not enough on its own. Outside a transaction it works; inside one the recovery re-read is the next statement and it fails too. **`RefreshDatabase` wraps every test in a transaction**, so this passes in production and 500s in the suite — which is exactly how it was found in `JoinHuddle` (2026-08-04). Fix: wrap the *expected-to-fail* insert in a nested `DB::transaction()`, which issues a SAVEPOINT and confines the rollback to that one statement. `FindOrCreateConversation` has the same latent shape and documents the assumption rather than savepointing it.

**Every new `ShouldBroadcast` event must be added to the `Event::fake([...])` list in `tests/Pest.php`.** The queue is `sync` under test, so an unfaked broadcast makes a real HTTP call to a Reverb that is not running. `HuddleChanged` and `HuddleActivity` were added for the same reason `BroadcastNotificationCreated` was.

**A factory's `definition()` must produce a usable model under `make()`, not just `create()`.** `make()` never fires model events, so anything assigned in `booted()` — `public_id` on `Huddle`, for instance — is null on a made instance, and a test that only ever calls `create()` will not notice. Assign it in both places.

**Gate command, in order:** `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, `php -d memory_limit=2G vendor/bin/pest --compact`, `npx vitest run`, `npx tsc --noEmit`, `npx eslint .`, `npx prettier --check "resources/js/**/*.{ts,tsx}"`, `npm run build`. Prettier runs *after* the others because it reformats files the earlier steps just touched.

**The full suite exceeds PHP's default 128 MB memory limit** (as of 2026-08-10) and dies with a bare `Allowed memory size … exhausted`, usually pointing at whatever file it happened to be in — `routes/web.php` or `ImageProcessor` — which reads like a bug in that file rather than a limit. `php artisan test` re-execs and **ignores `php -d`**, so raise it by calling Pest directly: `php -d memory_limit=2G vendor/bin/pest`. Confirm any suspected regression against a `git stash`ed baseline before chasing it; this one predates the assets work.

**PHPStan has a standing baseline of 9 errors** in files nobody is touching (`HuddleHistoryController`, `Project`, `UpdateProjectRequest`, `NotificationRecipients`, `ProjectBoard`, `WebhookPayload`). "0 errors" is not the pass condition — "still 9, none in files I wrote" is. Check by stashing and re-running if the number moves.

**An unordered `pluck()`/`first()` in a test is a latent flake.** Postgres promises no ordering, so an assertion like "the first registration got the undecorated username" passes or fails depending on physical row layout — which changes as unrelated tests are added ahead of it. `RegistrationTest` failed this way on 2026-08-10 purely because four new tests shifted the order; it passed in isolation. Add `orderBy('id')` rather than re-running until green.

See [[snack-phase-status]] and [[snack-local-serving]].
