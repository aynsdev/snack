---
name: snack-webhooks
description: "Incoming webhooks — the URL is the whole credential, so the channel is a column and the payload is rebuilt key by key; plus the two traps (factory clone, sender-only grouping)."
metadata: 
  node_type: memory
  type: project
  originSessionId: 90ec4446-bc7e-4d30-9ca3-954d419b942c
  modified: 2026-08-07T01:37:27.424Z
---

Built 2026-08-06 on `feature/webhooks`. An outside system POSTs JSON to `/hooks/{token}` and it becomes a message in one channel. Slack's incoming-webhook shape deliberately, so existing integrations only need their URL changed. **Outgoing webhooks / an Events API (Snack → your app) were explicitly not built.**

**The default security model is one sentence: the blast radius of the URL is the URL.** The caller is three lines of curl in a deploy script. What makes that acceptable is that a leaked hook can only post into one channel, as a visible bot:
- `channel_id` is a **column**, never a payload field. Slack's legacy `channel` override is the one part of the shape not implemented — it would turn every leaked URL into a workspace-wide megaphone.
- The channel also cannot be changed by an edit. By the time anyone edits a hook, its URL is in somebody's CI config; re-aiming would silently redirect a live credential. Moving = new hook + revoke.
- `token_hash` (SHA-256) is what lookups touch; `token` is the encrypted plaintext, same pair and same reason as `workspace_invitations.link_token` — "copy URL" must work next month.
- Unknown token, revoked, and deleted-channel all return the **same 404**, so the endpoint is not an oracle for which tokens are real.

**Everything in the payload is rebuilt key by key** (`App\Support\WebhookPayload`), never copied. It lands in `messages.metadata`, which the client reads back and draws — copying the array wholesale would let an unauthenticated request choose keys in a column the frontend trusts. `icon_url` is ignored entirely (would make Snack fetch a remote image on an anonymous say-so); `title_link` is http/https only.

**One bot account per hook**, not one per workspace like [[snack-standups]] — the reader's question is "what posted this", and a shared account would render every integration as the same author. Created lazily on first delivery. The `webhook` metadata block is written on **every** delivery including plain text: it is the attribution *snapshot*, and the bot's name follows hook renames, so writing it only when it differs would let a rename rewrite history.

**Optional per-hook signing secret** (added later the same week) is the answer to "but the URL leaks". Opt-in, because a scheme three lines of curl cannot satisfy is one people work around. HMAC-SHA256 over `v1:{timestamp}:{raw body}`, sent as `X-Snack-Timestamp` + `X-Snack-Signature: v1=<hex>`. The three things that make it actually work:
- **The timestamp is inside the signed string, not beside it.** Signing the body alone yields a signature valid forever, and a replayed "deploy succeeded" is a lie about the present, not a harmless duplicate. Inside the HMAC, a fresh timestamp can't be bolted onto an old signature.
- **The raw bytes are signed**, never a re-encoding. The #1 caller mistake is `Http::post($url, $array)` re-encoding after you signed your own copy — the on-screen docs say `withBody` explicitly.
- **`hash_equals`**, not `===`; and the secret is stored **encrypted, not hashed**, because verifying an HMAC needs the key itself.

Signature failure returns **401 with a reason**, deliberately breaking the no-oracle rule the 404 path keeps. Justification: the 404 stops an attacker *discovering* a token by probing; this request already has one and lacks the secret, which is exactly what the secret is for. A silent 404 would send a developer with a signing bug hunting a revoked hook instead.

Enable / disable / rotate are three modes on one endpoint, three audit cases, and **their own policy method** — disabling *removes* a control, so it must not ride along with permission to rename. Re-enabling an already-signed hook is a no-op rather than a silent rotation (re-saving a form must not break a live integration). No dual-secret grace window: that would keep a leaked secret valid for the window rotation exists to end.

**Revoke is undoable** (`restore`), and restore brings back the *same* token — a new one would make "turn back on" a lie and break every config holding the URL. The leak case is steered to Delete via dialog copy, because nothing on the row records *why* somebody revoked.

Three traps, all cost real time:
- **Factory `create($overrides)` builds a clone** and runs `definition()` on it. A plaintext stashed on `$this` in `definition()` is set on an object the caller never sees — tests looked fine until any override was passed. Read `$webhook->token` instead; the encrypted cast decrypts it.
- **`continuesGroup` keyed on sender id alone.** One hook posting as "CI" then "Alerts" is one account, so the second folded into the first's block under the wrong name. Now compares `webhook.username` too.
- **Two-click confirm state outlived the action that consumed it.** Revoke armed "Turn it off?", the row switched to its revoked shape, and restoring brought the button back *already armed* — handing someone who had just undone an accident a hair-trigger to repeat it. Fixed by keying the `<li>` on `id-is_revoked-signing_required` so a state flip remounts the row and discards the flags. Any future confirm-in-a-row-that-changes-shape has this bug by default.

Routes live in `routes/webhooks.php`, registered via `then:` in `bootstrap/app.php` **outside the `web` group** — a deploy script has no session and no CSRF token, and `web` would reject every delivery and mint a session for a CI runner. `hooks/*` is also added to `shouldRenderJsonWhen`, because curl sends no Accept header so `expectsJson()` is false and a 422 would otherwise be a 302 to a login page. Throttled per token (hashed into the key), never per IP — see [[snack-test-and-gate-traps]] for the sibling gate-order issues.

The admin screen is admin-gated on **viewing**, not just editing: it prints live tokens, so opening it is the same right as issuing one.
