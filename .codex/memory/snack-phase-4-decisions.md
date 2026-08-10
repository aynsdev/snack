---
name: snack-phase-4-decisions
description: "Design calls made building Snack's Phase 4 (threads, reactions, mentions, typing, saved/pinned) that are not obvious from the code."
metadata: 
  node_type: memory
  type: project
  originSessionId: 5a518eb0-5078-4fe3-b614-445ac63f17a4
  modified: 2026-07-31T02:59:27.206Z
---

Decisions from Phase 4 (2026-07-31) that shape how later phases should extend this code.

**Threads are flat and are not container activity.** Replying to a reply joins the same thread. A reply deliberately leaves `channels.message_count` and `last_message_at` alone, because the timeline (`scopeRootLevel`) and the unread badge both exclude replies — bumping the container would order the sidebar by messages it cannot show. Following a thread is implicit: replying subscribes you and marks your own reply read; the root's author is pulled in unread the first time someone answers them.

**`BroadcastsToContainer` now routes replies to `thread.{root}` (private), not the container.** The container hears about it via `ThreadActivity`, which carries counts and no body. Any new message event gets this routing for free — and must not assume the container channel.

**Idempotent pairs, never toggles.** Reactions and pins use `store`/`destroy` rather than one toggle endpoint, because they are the controls people double-click. All of them insert with `insertOrIgnore` (ON CONFLICT DO NOTHING) and use the affected-row count as the "did anything change, does anyone need telling" signal. **Catching a unique violation does not work here:** under Postgres a failed statement aborts the whole surrounding transaction, so the "harmless" duplicate takes the request's other work down with it. This bit once, in tests, under `RefreshDatabase`.

**Broadcasts carry full state, not deltas.** `reaction.changed` sends the message's whole reaction summary; `pin.changed` sends the whole message. A client that missed one broadcast is corrected by the next rather than drifting until reload.

**Viewer-specific fields must survive `upsert`.** `can`, `is_saved` — anything a broadcast cannot carry — is preserved from the HTTP-loaded copy when a broadcast replaces a message. Forgetting this silently un-bookmarks a message or vanishes the author's edit controls the moment anyone edits it. There are tests for both.

**Mentions resolve against the container, never the users table.** Mentioning someone not in the channel writes no row: no badge, no notification pointing at a message they cannot open, and no using `@` to probe who exists. `@everyone`/`@here` are gated on `canMentionEveryone()`; without it the words send and reach nobody. Editing re-syncs rather than appends, and a surviving mention keeps its `read_at`.

**Two mention parsers must stay in step:** `App\Services\MentionParser` and `resources/js/lib/message-format.ts` share a handle grammar and both blank out code before scanning. Diverging means a message that notifies someone it never highlighted. Same pairing exists for reactions: `config/snack.php` `reactions.available` is the server allowlist, `resources/js/lib/emoji.ts` is the glyph map.

**Typing never reaches the server.** Presence-channel whispers only — no controller, no queue, no row. Reverb's `accept_client_events_from = members` is what gates it. Every signal carries its own expiry and is swept on a timer, so a closed tab leaves seconds of stale indicator rather than a permanent one.

**Pins are shared, saves are private** — same gesture, deliberately different authorization. `pin` resolves to the container's `pinMessages` (posting rights) and broadcasts; `save` resolves to plain `view` and broadcasts nothing, because telling the channel what you are keeping track of defeats the point. Anyone who could pin can unpin, or stale pins would outlive whoever made them.

**Layout resolution in `resources/js/app.tsx` is a denylist, and must stay one.** It used to be an allowlist of prefixes that got `WorkspaceLayout`, with everything else falling through to the starter kit's `AppLayout` — so Threads, Mentions, and Saved all shipped wearing a different sidebar, rendering perfectly with nothing to warn you. Now `WorkspaceLayout` is the `default` and `STANDALONE_PAGES` is the short exception list, so a new page inside a workspace gets the right chrome by doing nothing. Settings nests as `[WorkspaceLayout, SettingsLayout]`; because `/settings/*` sits outside the `/w/{workspace}` prefix, `HandleInertiaRequests` falls back to the `snack.current_workspace` session value — **re-resolved and re-authorized every request**, since a session outlives being removed or suspended. Any page inside the shell must bring its own `overflow-y-auto`; the shell's `<main>` does not scroll.

**`WorkspaceContext` is a scoped binding, and the test app does not flush scoped instances between `$this->get()` calls.** A test that visits a workspace page and then a non-workspace page still sees the first request's *resolved* context, so it never exercises the unresolved path. Seed `withSession(['snack.current_workspace' => ...])` instead. Production is unaffected — php-fpm forks per request and Octane flushes scoped bindings — so do not add defensive code for it.

**Tooling gotchas hit this phase:** `php artisan wayfinder:generate` must be run with `--with-form` (the vite config sets `formVariants: true`) or the `.form` variants silently disappear from every route. Pint's `phpdoc_separation` reflows docblock lines starting with `@word`, so ASCII tables documenting `@username` syntax need the sigils quoted.

See [[snack-phase-status]] and [[snack-spec-constraints]].
