---
name: snack-realtime-latency
description: "Snack's message broadcasts are queued jobs, so queue pickup latency IS chat latency — block_for null cost a measured 2.7s per message, and dispatch order put the broadcast behind two heavy jobs."
metadata: 
  node_type: memory
  type: project
  modified: 2026-08-03T03:54:01.683Z
  originSessionId: 1d00895f-0619-4f28-9389-8fe7d66503a7
---

Found 2026-08-03 chasing "delay between the delivering of messages" in production.

## The load-bearing fact

`MessageSent` (and every sibling event) is `ShouldBroadcast`, **not** `ShouldBroadcastNow`. The socket write therefore happens on a queue worker, not in the request. So **anything that slows the queue slows chat, one-for-one**. Treat the queue as part of the realtime path, not as background infrastructure.

## The two causes, both measured

1. **`config/queue.php` redis `block_for` was `null`.** The worker does a non-blocking pop, finds nothing, and sleeps out `queue:work --sleep` (default 3s). Measured on an idle queue: **~2683ms** of pure wait before pickup. Set to `5` (BLPOP): **~10ms**. Same probe both times — dispatch a closure that writes `microtime` to the cache, poll until it lands.
2. **Dispatch order in `SendMessage`.** `MessageSent::dispatch()` came *after* `IndexMessage` and `DeliverMessageNotifications`, and one worker takes the queue in order — so every message waited on full-text indexing and the whole notification fan-out. Moved first; nothing about the broadcast depends on either.

End-to-end after both: send returns in ~30–50ms, the entire job chain drains in ~77–154ms.

## What to check before blaming code again

- **The `database` queue driver has no `block_for`.** If production is on it, this fix does nothing — the worker always sleeps. Use Redis, or `queue:work --sleep=0`.
- **Config caching.** `php artisan config:cache` on deploy bakes the old value in; a stale cache silently keeps the 3-second floor.
- If broadcasts are ever moved to a dedicated queue for priority, the Forge/supervisor worker's `--queue=` list must be updated in the same change or those jobs stop running entirely — silent, and not visible in any test.

## Two broadcasts per message, and what each one reaches

`MessageSent` goes to the **container's presence channel**, so it reaches only people who already have that container open. Everything about a container you are *not* looking at — the unread badge, the arrival sound, the banner — needs a second, separate signal fanned out to each member's own `user.{id}` channel.

Conversations had `ConversationActivity` (dispatched from `SendConversationMessage`) from the start. Channels had **no counterpart at all** until 2026-08-03, which is why a message in another channel produced nothing until the reader reloaded. `ChannelActivity` + `useChannelSync` now mirror the conversation pair exactly. **Whenever you add a container type or a new "something happened here" affordance, it needs both halves.**

The fan-out goes to member user channels rather than one broadcast to `workspace.{id}`: the cheap version would let every workspace member observe the *timing* of activity in private channels they cannot see. Cost is a channel name per member per publish — worth re-examining before raising `snack.workspaces.member_limit` past 500.

## Verifying realtime locally

Reverb **and** a queue worker must both be running or nothing arrives; see [[snack-local-serving]].

- Send as another user from tinker while watching a browser tab: `app(SendMessage::class)->handle($channel, $user, 'probe')` — third arg is a plain **string**, not an array.
- **Use the same action the controller uses.** Channels go through `SendMessage`; conversations go through `SendConversationMessage`, and calling `SendMessage` directly on a conversation skips `ConversationActivity` entirely. A test that did this produced a convincing false negative ("DM badges don't update") that took a second pass to catch.
- The channel unread badge is a **dot with an `aria-label`**, not text — reading `textContent` says "no badge" when the badge is right there. Query `[aria-label*="unread"]`.
- To check the arrival sound, patch `HTMLMediaElement.prototype.play` and count calls; `use-notification-sound` swallows rejections by design, so a silent failure looks identical to never being called.

Related: [[snack-presence]] (why presence deliberately does *not* ride the socket).
