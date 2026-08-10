---
name: snack-conversation-public-id
description: Snack conversations are addressed by an opaque ULID public_id; the integer primary key never reaches the client.
metadata: 
  node_type: memory
  type: project
  originSessionId: b74f986f-b401-42e2-aa83-3ee69b4d9a3d
  modified: 2026-07-30T13:07:39.374Z
---

The user asked on 2026-07-30 that DM ids be opaque on the frontend. Conversations keep their auto-incrementing primary key internally — every FK, index, and cursor is built on it — but that integer **never leaves the server**. A `public_id` ULID column (unique, assigned in `Conversation::booted()`, exposed via `getRouteKeyName()`) is the only conversation identifier a client ever sees.

Everything outward-facing was switched together, and they must stay in step:

- `ConversationResource` emits `public_id` as `id`; `MessageResource.conversation_id` likewise.
- `UnreadCounter::forConversations()` keys its map by `conversations.public_id` (so the sidebar looks counts up by the same id it links with).
- All four conversation events broadcast the public id, and the **presence channel is named `conversation.{public_id}`** — `routes/channels.php` resolves it with `where('public_id', …)`, not `find()`.
- TS: `Conversation.id`, `Message.conversation_id`, and `UnreadState.conversations` keys are all `string`.

**Why:** `/dms/1` advertises that `/dms/2` exists, lets anyone measure how many private conversations the deployment holds, and turns a shared link into a probe. The policy refuses every such request anyway, but the count itself is a fact worth not publishing. ULID over UUIDv4 because it sorts by creation time, so the unique index is appended to rather than written across.

**How to apply:** never add a surface that emits `conversation->id`. Two tests pin this — "never puts the conversation primary key in a payload" and "addresses a conversation by an unguessable id" in `ConversationAccessTest`. Note the same reasoning has *not* been applied to channels (addressed by slug, which is already non-sequential) or workspaces.

See [[snack-container-abstraction]] and [[snack-phase-status]].
