---
name: snack-asset-library
description: "Asset library (brands, company docs): why an asset's file ownership lives on message_attachments.asset_id, the three guards that depend on it, and the folder-tree invariants."
metadata: 
  node_type: memory
  type: project
  originSessionId: 7bd599f3-0793-41d6-b354-e1d950beba9e
  modified: 2026-08-10T01:29:11.075Z
---

Built 2026-08-10 on `feature/assets`, filling the rail's long-standing "Assets — coming soon" gap. Folders + gallery + upload + search, at `/w/{workspace}/assets` and `/assets/folders/{assetFolder}`.

**The library is not the Files browser, deliberately.** Files lists what was *said* (every attachment on every readable message, flat, per-container visibility). The library lists what was *filed*. Merging them buries the logo under every screenshot pasted into #random. Consequently the library has **no private shelves** — every non-guest member sees all of it — so there is no per-row container check anywhere in it. Guests get `denyAsNotFound()`.

**The one design decision everything else follows from: ownership of an asset's bytes is recorded on `message_attachments.asset_id`, not on `assets.attachment_id`.** The codebase already refuses to write the storage apparatus twice (see the `add_task_id_to_message_attachments` migration's rationale), so an asset's file is an ordinary attachment. Putting the pointer on the *attachment* is what makes three existing paths safe **by construction** rather than by remembering:

- `snack:prune-uploads` → `scopeOrphaned()` now requires all three owners null. With the pointer on the other table, every logo in the library would be swept within 24h.
- `UploadController::destroy` (the composer tray) → now also requires `asset_id === null`.
- `ResolvesAttachments` → uses `orphaned()`, so a crafted send cannot claim an asset's file onto a message.

Each of those has its own test in `tests/Feature/Assets/AssetUploadTest.php`. A DB check constraint counts the three owner columns (`<= 1`) instead of the old two-column OR, and a partial unique index on `asset_id` enforces one file per asset. `DownloadController` gained one branch: `asset_id !== null` → `Gate::authorize('view', $asset)`.

**Upload is one request, unlike the composer.** The asset row is created first inside a transaction, then `StoreUpload(..., asset: $asset)` writes the attachment pointing at it. There is no unclaimed window at all. Library files go to `workspaces/{id}/assets/{Y/m}` rather than `.../attachments/…` — not for authorization (nothing is served by path) but because the two have different lifetimes and backup value.

**Folder-tree invariants** (`app/Actions/Assets/`):
- `AssetFolder::MAX_DEPTH = 5`, a class constant not config, so the model's own walks stay unit-testable.
- A move must reject the destination being the folder *or any of its descendants*. The failure is silent: every node in a detached subtree still has a parent, so nothing looks broken — it is just unreachable from the root forever.
- Depth is checked against the **subtree's height**, not just the moved folder, or a tall subtree can be dropped near the bound.
- Sibling name uniqueness needs **two** partial unique indexes on `lower(name)` — Postgres treats NULL `parent_id` as distinct, so one `(workspace_id, parent_id, name)` constraint would allow two top-level "Brand"s.
- Deleting a folder sweeps the disk *before* letting the FK cascade take the rows: a cascade cannot reach object storage, and the orphan sweep reads rows that would already be gone.

**Deletion is the only audited action** (`asset.deleted`, `asset.folder_deleted`). Adds and renames are visible in the library itself; a row per upload would bury the log.

Traps re-confirmed while building this: the `Gate::inspect` one from [[snack-channel-grouping]] (all four asset FormRequests return `Response`, not `bool`, or the guest 404 flattens to 403), and `scopeBindings()` — the route param had to be `{assetFolder}` so it resolves through `Workspace::assetFolders()`.

See [[snack-container-abstraction]], [[snack-object-storage]], [[snack-navigation-shell]].
