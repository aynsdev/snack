---
name: snack-spec-constraints
description: "Hard rules the user set for Snack — server-side verification of all identifiers, no row-per-user-per-message, no loading full history, no copying Slack."
metadata: 
  node_type: memory
  type: project
  originSessionId: 93966f66-7a4d-4ea9-ad1c-7b8b9437d108
  modified: 2026-07-29T23:32:55.335Z
---

Non-negotiable constraints from the user's original Snack specification. They are not written down in the repo, so re-read them before designing any new phase:

- Never trust workspace, channel, conversation, sender, or role identifiers supplied by the client without server-side verification.
- Do not expose private file paths.
- Prevent users from accessing records belonging to workspaces where they are not members.
- Do not copy Slack's source code, trademarked assets, exact UI, illustrations, wording, or branding. Snack is inspired by the collaboration patterns only; branding is original ("Small chats. Big work.", `#F97316 #FDBA74 #171717 #F3F4F6 #FFFFFF`, Geist).
- Do not create one database row per user per message (read state is a last-read pointer on the membership row).
- Do not load an entire channel history at once (cursor pagination on `messages.id`).
- Do not generate the entire application in one unstructured response — work phase by phase.

**Why:** these shaped concrete Phase 2 choices, e.g. message bodies are stored as author source text and tokenized client-side (no `dangerouslySetInnerHTML` anywhere), and `FormRequest::authorize()` returns an `Illuminate\Auth\Access\Response` so `denyAsNotFound()` still yields 404 instead of leaking a private channel's existence.

**How to apply:** treat any client-supplied id as untrusted input to be resolved and authorized server-side, and check a new feature against this list before writing it, not after review.

See [[snack-phase-status]].
