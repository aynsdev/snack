---
name: snack-container-abstraction
description: "Snack channels and conversations share one message pipeline (Channel|Conversation server-side, useMessageTimeline client-side) — extend both, never fork one."
metadata: 
  node_type: memory
  type: project
  originSessionId: b74f986f-b401-42e2-aa83-3ee69b4d9a3d
  modified: 2026-07-30T05:12:42.479Z
---

Phase 3 deliberately made channels and DMs share one message pipeline rather than growing a parallel one. Anything touching messages must extend the shared path, not fork it:

- **Server:** `MessageHistory::page()` and `Actions\Messages\SendMessage::handle()` both take `Channel|Conversation $container`. `PostSystemMessage` likewise. `MessagePolicy` delegates every decision to the container's own policy via `containerResponse()`.
- **Client:** `hooks/use-message-timeline.ts` holds all timeline behaviour (id-ordered upsert, optimistic-send reconciliation, cursor pagination, rollback). `use-channel-messages.ts` and `use-conversation-messages.ts` are thin adapters supplying a `TimelineEndpoints` object only.
- **Two participant relations, not one:** `Conversation::participants` is routinely eager-loaded *constrained to one viewer* (that is what stops a page of messages becoming an N+1 of policy checks), so rosters read `Conversation::activeUsers` instead. Reading a roster from `participants` silently yields a one-person conversation.

**Why:** every hard part (ordering by monotonic id, de-duplicating a message that arrives by both HTTP and WebSocket, rolling back a rejected edit) is the kind of thing that goes subtly wrong rather than obviously broken when duplicated.

**How to apply:** for a new message feature, change the shared implementation and let both containers inherit it. If a difference is genuinely container-specific, inject it (as `TimelineEndpoints` does) rather than branching inside the shared code.

A related invariant found while testing Phase 3: a policy must settle **container visibility first**, before cheaper checks like authorship. `MessagePolicy::update()` and `ConversationPolicy::post()` both had to be reordered — answering "you can only edit your own messages" or "you are no longer part of this" to a non-participant confirms the record exists, which is exactly what the 404s elsewhere protect.

See [[snack-spec-constraints]] and [[snack-phase-status]].
