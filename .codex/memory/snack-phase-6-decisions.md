---
name: snack-phase-6-decisions
description: "Phase 6 notifications & administration: why the inbox stores only directed events, the reason precedence, the away rule, and where audit entries are written from."
metadata: 
  node_type: memory
  type: project
  originSessionId: ab4c6e02-468d-4508-bfd9-0947137b8fce
  modified: 2026-08-04T02:16:37.999Z
---

Phase 6 shipped 2026-07-31 (notification centre, preferences, email + digest, workspace settings, audit viewer, channel settings). Design calls that are not recoverable from the code.

## The inbox stores only *directed* events

`NotificationReason` has three cases — Mention, DirectMessage, ThreadReply — and deliberately **no case for an ordinary channel message**. A channel set to `NotificationLevel::All` still drives the live alert and the away email, but writes **no** database row.

Why: a row per member per message is the exact shape [[snack-spec-constraints]] rules out. One 200-member channel would write 200 rows a message, and the resulting inbox would be a second, worse copy of the channel. Every case that *is* stored is bounded by attention rather than by traffic.

If a future phase is asked for "notify me about everything", the answer is the alert and the email, not an inbox row.

## Reason precedence, and one row per event

`NotificationRecipients::for()` assigns weakest-first so stronger reasons overwrite: DirectMessage → ThreadReply → Mention. A reply that also names you produces **one** row carrying Mention. Mentions are read from the materialized `mentions` rows written by `SyncMentions`, never re-parsed — re-deriving would be a second, subtly different answer to a settled question.

Mute is not a blanket silence: `NotificationReason::outranksMute()` lets a direct mention through a muted container, because muting says "stop telling me about the traffic here", not "stop telling me when I am addressed by name". Following a thread outranks the container's broad level but not a mute — hence `wantsNotificationForThreadReply()` on both membership models.

## DM notifications coalesce; mentions do not (added 2026-08-04)

The inbox being bounded by *attention* was only half true: a DM raised a row per **message**, so four short sentences cost four rows, four broadcasts and four emails describing one interruption. `App\Services\NotificationCoalescer` now rewrites the row already sitting unread for that conversation — latest preview, latest `message_id`, `count + 1`, and a bumped `created_at` so it floats back to the top — and `NotificationDispatcher::deliver()` drops those recipients before constructing anything, so no second notification and therefore **no second email** is built at all.

Three decisions worth keeping:

- **Bounded by a window as well as by read state** (`snack.notifications.coalesce_minutes`, 60). "Fold while unread" alone means somebody who never opens their inbox has one permanently-unread row absorbing every DM they are ever sent, and the away-email stops arriving after the first one — worse than the pile-up. Worst case is now one row and one email per conversation per hour.
- **`coalescesWhileUnread()` is true for DirectMessage only.** A DM fires on traffic; a mention is a directed act, and being named three times is three things to look at. ThreadReply is the obvious next candidate and needs only that one line changed.
- **The fold is re-broadcast as `BroadcastNotificationCreated` under the *existing* row's id** (set `$notification->id` before dispatching). The client keys its list by id, so this is one event and one code path for both cases — but `use-notifications.ts` had to stop *ignoring* a known id and start replacing-and-hoisting instead, or the reader keeps staring at the first message of a conversation that has moved on.

`count` is now on every row (defaulted to 1 in `MessageNotification::toArray()`, spread last so a folded payload wins) so the client never treats its absence as a third case.

## The away rule (email)

`NotificationDelivery::shouldMail()` — presence is Offline **or** `last_seen_at` older than `snack.notifications.idle_minutes`. Presence alone is wrong: a live socket in a tab untouched since lunch reads as present and silently drops the email. DND and the member's own quiet hours suppress **mail only** — the database row is always written, because the centre is a record, not an interruption, and an empty inbox after a quiet evening is worse than a full one.

## Two Laravel notification traps

- **`broadcastType()` is not optional.** `BroadcastNotificationCreated` overwrites `type` in the payload *after* `toBroadcast()` runs, defaulting to the class name. Setting it inside `toBroadcast()` does nothing. Without `broadcastType()`, the client receives a PHP namespace where it switches on a reason — this blanked the notifications page in the browser and no test caught it. There is now a regression test pinning the broadcast payload's shape to `NotificationResource`'s.
- **`workspace_id` needs a custom channel.** Laravel's database channel writes only `id`/`type`/`data`/`read_at`. `WorkspaceDatabaseChannel` (registered over `database` in `AppServiceProvider`, taking the container as a **parameter** — `$this` inside a Manager custom creator is the ChannelManager, not the provider) fills the tenant column the unread partial index is keyed on.

## Audit: where entries are written from

`ChannelMemberAdded`/`Removed` are recorded in **`ChannelMembershipController`, not in `JoinChannel`**. That action is also the enrolment path for automatic default-channel joins on invitation acceptance; logging from inside it would write an entry per default channel per person, which `member.joined` already says once. A test pins this.

`UpdateWorkspace` records `WorkspaceUpdated` and `WorkspaceSettingsUpdated` **separately from one form** — renaming and closing the door on outsiders are different events, and an admin should be able to filter for the second. A no-op save writes neither; same for `UpdateChannel` and `SetChannelArchived`.

## Slugs: channel follows the name, workspace does not

A channel renamed to "Product Design" that still answers to `#design` contradicts the UI everywhere, so `UpdateChannel` regenerates the slug (`SlugGenerator::forChannel` takes `ignoreId` — without it a no-op rename walks the channel to `design-2`) and the controller redirects to the new one. Links pasted before the rename die; the dialog says so.

A workspace slug is the root of every bookmark, every invitation link already sent, and the tenant key in the session. It is set at creation and never regenerated.

## A policy bug this surfaced

`ChannelPolicy::archive` delegated to `update`, which refuses archived channels — so **restoring was impossible** and `ChannelUnarchived` was unreachable by construction. Now both share a private `managementRights()` helper; only `update` adds the archived refusal. Watch for this shape wherever one policy method delegates to another that carries a state guard.

See [[snack-phase-status]], [[snack-postgres-timezone]], [[snack-container-abstraction]].
