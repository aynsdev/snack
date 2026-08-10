---
name: snack-phase-5-decisions
description: "Phase 5 Files & Search design calls — two-step uploads, container-derived file access, tsvector fed by a queued job, and snippets as segments rather than markup."
metadata: 
  node_type: memory
  type: project
  originSessionId: 5d860785-d295-43ee-8e78-483fe9170fcd
  modified: 2026-07-31T04:37:10.613Z
---

Design decisions made building Snack Phase 5 (Files & Search, plus avatar upload) on 2026-07-31. Each was a real fork in the road; changing one later means changing the others.

**Uploads are two-step.** A file goes up while the composer is still open (`POST w/{slug}/uploads`), landing with a null `message_id`; sending the message *claims* it. That is what allows a progress bar and stops a 40 MB video being re-sent per typo. The cost is orphans, swept by `snack:prune-uploads` nightly — never by the client, since the usual way to abandon a composer is closing the tab. The claim is one `UPDATE … WHERE message_id IS NULL` inside the send transaction, so a double-clicked send cannot have two messages claim one file.

**`attachment_ids` is resolved, never trusted** — `App\Http\Requests\Concerns\ResolvesAttachments`, modelled exactly on `ResolvesThreadParent`. Three predicates on the query: this workspace, this uploader, not yet attached. A partial match aborts the whole send with 404, never a quiet partial send.

**File routes are deliberately NOT workspace-prefixed** (`/files/{attachment}`, outside `w/{slug}`). Their URLs are minted by queued broadcasts, which have no request and therefore no resolved workspace, and a slug in the path would break every existing link on a rename. Access derives from the message's container policy on every hit; `signed` only bounds link lifetime. Signed-URL expiry is **snapped to a fixed window** so the URL is stable and browser-cacheable — a naive `addMinutes(30)` produces a new URL per render and re-downloads every image on every visit.

**Inline is a narrower door than download.** Only a fixed raster allowlist ever gets `Content-Disposition: inline`; SVG is forced to attachment (it is a document that can carry script, and inline on our own origin is stored XSS). Plus `nosniff` and `default-src 'none'; sandbox`.

**`search_vector` is fed by `App\Jobs\IndexMessage`**, not a generated column — it folds in sender name, channel name, and attachment filenames, all of which live elsewhere and can change after the message is written. Weights A body / B sender / C channel / D filenames; ts_rank's array is `{D,C,B,A}` order. Filenames are `regexp_replace`d to spaces before tokenizing or "Q3-report.pdf" is one token and "report" finds nothing. Dispatched on send, on edit, and again when `ProcessAttachment` finishes. `snack:reindex-messages` backfills; `--all` is required after any dictionary or weight change or search silently returns nothing.

**Search snippets are `{text, highlight}` segments, not markup.** `ts_headline` marks hits with sentinel `\x02`/`\x03` — safe because `MessageBody` strips exactly that control range from every stored body — and the server splits them. This extends the no-`dangerouslySetInnerHTML` guarantee to snippets. See [[snack-spec-constraints]].

**`App\Services\MessageVisibility` is the one narrowing rule** shared by search and the file browser — the only two read paths that start from the whole workspace instead of an authorized container. It delegates to `Channel::scopeVisibleTo`. Filters the user types are applied *on top of* it, never instead, so `in:#private-channel` narrows to nothing rather than reaching in.

**Attachments do not make a message `type = file`.** Carrying files is a property (`has_attachments`), not a kind; spending the type would cost the announcement distinction that decides who may post. `body` became `required_without:attachment_ids` — files alone are a message.

**Avatars break the private-disk rule on purpose.** They render beside every message their owner ever wrote, so a signed per-viewer route would be a DB round trip per face. Public disk, but always re-encoded through `ImageProcessor` (GD, no new dependency) — which strips EXIF GPS, bounds the size, and means a file that merely claims to be an image cannot survive. New ULID filename per upload, so the change is immediate rather than waiting out caches.

**`SearchQuery::MIN_TERM_LENGTH` is a constant, not config** — a value object that reaches into the container cannot be unit-tested without booting the framework.

See [[snack-phase-status]] and [[snack-container-abstraction]].
