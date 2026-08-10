---
name: snack-invite-links
description: "Shareable invite links are workspace_invitations rows with email NULL — same token, same landing page; the two behavioural differences and why link_token is stored encrypted."
metadata: 
  node_type: memory
  type: project
  modified: 2026-08-03T05:13:06.371Z
  originSessionId: 1d00895f-0619-4f28-9389-8fe7d66503a7
---

Added 2026-08-03. Before this, the only way into a workspace was an emailed, single-use, address-bound invitation.

## The shape

**A link is a `workspace_invitations` row with `email` left NULL.** Not a second table — that would have meant a second token scheme, a second landing page, and a second set of expiry rules free to drift. Everything downstream (the `invitations/{token}` route, `RegistrationGate`, revocation, the audit trail) works unchanged.

Exactly two behaviours differ, and both fall out of `email IS NULL`:

1. **Addressed to nobody.** `isAddressedTo()` returns true for everyone, so a forwarded link is the feature rather than the attack. `RegistrationGate::requiredEmail()` returns null, so registration stops binding to an address — the existing null-guard in `CreateNewUser` already handled this.
2. **Survives redemption.** `accepted_at` is what makes an invitation single-use, so a link must **never** set it — doing so burns the whole link on the first person through. `use_count` is the counter, `max_uses` bounds it (null = unlimited), and both live in one `increment()` with the limit in the same WHERE so two simultaneous clicks cannot both pass a limit of 5 at count 4.

## Why `link_token` exists alongside `token`

`token` stays a SHA-256 hash and is still the only thing lookups touch. But a link's **only delivery mechanism is the admin screen** — "copy invite link" has to work next week — and a hash cannot be un-hashed. So the plaintext is also stored in `link_token` with Laravel's `encrypted` cast: retrievable with APP_KEY, useless in a bare database dump. Emailed invitations leave it null; their plaintext genuinely only ever exists in the message.

`WorkspaceInvitationResource::withUrl()` is opt-in per call site rather than derived from `isLink()`, because the URL *is* the credential.

## Decisions worth not re-litigating

- **One live link per workspace**, enforced by a partial unique index (`WHERE email IS NULL AND accepted_at IS NULL AND revoked_at IS NULL`). A plain `(workspace_id, email)` unique would allow unlimited links — Postgres treats NULLs as distinct. Issuing a new link revokes the old in the same transaction, which is what makes "regenerate" a real safety control.
- **No guest links.** A guest is auto-joined to no channels, so one arriving through a shared URL lands in an empty workspace with nobody expecting them. Rejected in `StoreInviteLinkRequest`, not silently rewritten.
- Link TTL (30d) is deliberately longer than an emailed invite (7d): a link lives in an onboarding doc, not an inbox.
- The admin UI shows the URL in a **readonly input**, not just text — `navigator.clipboard` is undefined on insecure origins (see [[snack-insecure-context-apis]]), so the copy button must not be the only way to get the link out.

## Traps hit

- `pluck('action')` on audit logs returns **AuditAction enum instances** (the column is cast), so asserting against `->value` strings silently fails against every row. Map to values first.
- A migration whose `down()` drops a column added in a later edit of the same file cannot roll back. Use `DROP COLUMN IF EXISTS`.
- PHPStan's `array.duplicateKey` caught a **duplicate `presence` key in config/snack.php** — an earlier block appended in the same session silently overwrote `typing_ttl_seconds`. Tests all passed; only PHPStan saw it. Run `composer types:check`, not just the suite.
