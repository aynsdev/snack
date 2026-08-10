---
name: snack-standups
description: "Standups and Snack's first bot: why the bot is a real user row, why the channel is the roster, and the two idempotence guards that make a per-minute scheduler safe to run."
metadata: 
  node_type: memory
  type: project
  originSessionId: 2d92dd65-6ea5-446b-8c97-852d9c24a128
  modified: 2026-08-06T08:14:55.442Z
---

Built 2026-08-06 on `feature/standup`. A recurring set of questions the **Standup bot** DMs a channel's members on a schedule, collecting answers privately and posting one write-up back to that channel.

**The bot is an ordinary `users` row, flagged `is_bot`.** Everything that delivers a message is built on a sender that exists — a DM needs a conversation, a conversation needs participants, the sidebar renders an author. A second "sender type" would have meant a nullable column on `messages` and a branch in every query that draws an author. The flag's whole job is the places a bot must *not* count as a person: `Workspace::humanMemberCount()` (seat limit), `NotificationRecipients` (would queue email to an address nobody reads), and the admin roster. It deliberately **stays** in the DM roster — that conversation is where it asks. One bot **per workspace**, keyed on `standup-bot+{workspace->id}@snack.invalid`; a shared bot would be a row that is a member of every tenant.

**The channel *is* the participant list.** There is no participant picker and that is the design: a second list of people beside the channel's own means two answers to "who is on the team" and a daily chance for them to disagree. Adding somebody to a standup = adding them to the channel. `Standup::participants()` excludes bots and suspended memberships.

**Two idempotence guards, both in the database, because asking DMs everybody.** The scheduler runs **every minute** (09:30 has to mean 09:30 — the digest can be hourly, a standup cannot), so "did we ask today?" cannot be inferred from the clock: unique `(standup_id, scheduled_for)` on `standup_runs` and unique `(standup_run_id, user_id)` on `standup_responses`. Both catch-and-reread on violation, and **both wrap the failing insert in a nested `DB::transaction()`** for the savepoint — see [[snack-test-and-gate-traps]], Postgres otherwise poisons the surrounding transaction and it 500s only under `RefreshDatabase`.

**The ask is resumable, not transactional.** Each person's row is claimed before their message is sent and `asked_at` is stamped only when all are reached, so a dead worker resumes exactly where it stopped. Wrapping the fan-out in a transaction would roll back nine people's rows while their DMs stayed sent.

**`Standup::dueDate()` asks "is there an unhonoured ask time in the recent past", not "is it 09:30 now"** — minutes get missed. Bounded by `snack.standups.catch_up_minutes` (60), or a deployment that was down overnight wakes up and DMs everyone about a standup whose day is over. `scheduled_for` is a **date in the standup's own timezone** (a standup is a shared ceremony — one moment, not one per person; the digest is the opposite and stores no timezone).

**The prompt card rides on the message's `metadata`, not on a fetch.** A DM with a month of standups draws every card with zero requests and survives a reload. Submitting **rewrites that block in place** (`saveQuietly`, no broadcast) which is what retires the button — and it rewrites *every* card for the response scoped by `conversation_id` + a jsonb `where`, because the reminder carries a card too. Built once in `App\Support\StandupCard`. `MessageResource` exposes only `metadata['standup']`, never `metadata` wholesale.

**Answers snapshot the question text they were asked** (`standup_responses.answers` is `{question, answer}` pairs). Reading the standup's *current* wording would silently refile last week's replies under this week's questions — which reads as true and is not.

**Gotchas hit while building:** `Rule::exists()->where('col', false)` renders the rule to a **string**, turning `false` into `''`, which Postgres rejects for a boolean column — 500, not a validation failure. Use `->where(fn ($q) => $q->where('col', false))`. A `Command` subclass cannot have a private `ask()` — it collides with the prompt helper. A FormRequest's `authorize()` must return the gate's `Response` (via `Gate::inspect`) rather than a bool, or `denyAsNotFound()` collapses to 403.

`--force` asks and `--close` writes up, as **separate** flags: one that did both made trying a standup out impossible, since the run it opened was closed again in the same invocation.

See [[snack-container-abstraction]], [[snack-dm-roster]], [[snack-navigation-shell]].
