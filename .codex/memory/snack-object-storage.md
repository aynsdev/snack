---
name: snack-object-storage
description: "DigitalOcean Spaces for production storage: the two disks, the four silent-failure bugs found wiring up the first real bucket, why the suite could write to it, and how existing files are migrated."
metadata:
  node_type: memory
  type: project
  originSessionId: 7bd599f3-0793-41d6-b354-e1d950beba9e
  modified: 2026-08-10T02:22:13.352Z
---

Added 2026-08-10 at the user's request. Required installing `league/flysystem-aws-s3-v3`, which was not previously a dependency.

## The two disks

Snack stores two kinds of thing and they have opposite access rules.

- **`spaces` — private.** Message attachments, task files, asset library files. Nothing is ever served by URL; every read goes through `DownloadController`, which re-authorizes the viewer on every hit. The bucket must be private *at the bucket level*: an object in it reachable without the app defeats every policy in the product.
- **`spaces_public` — public, CDN-fronted.** Only avatars and workspace logos, which render beside every message for everyone who can read it — a signed per-viewer route there would be a DB round trip per face on screen. `SPACES_PUBLIC_URL` must be the `.cdn.` hostname or images are billed as origin bandwidth.

`SPACES_PUBLIC_BUCKET` falls back to `SPACES_BUCKET` (one bucket, per-object ACLs), but a second bucket is safer — it lets the private one be locked down at the bucket level rather than trusting every write to ask for the right visibility. `use_path_style_endpoint` stays **false**: Spaces is virtual-host addressed, and path style yields URLs it answers with 403.

## Four bugs, one symptom

Everything below presented identically as **"uploads silently do nothing"**. That is the theme worth carrying forward, not the individual fixes.

1. **`SPACES_ENDPOINT` is the *region* endpoint** (`https://sgp1.digitaloceanspaces.com`), never the bucket's URL. DigitalOcean's panel displays `https://your-bucket.sgp1.digitaloceanspaces.com`, so that is what gets pasted in — and the SDK prepends the bucket *again*, giving `bucket.bucket.sgp1.…`, which fails TLS because a wildcard cert matches one label, not two.
2. **`env('X', $default)` does not fire on a present-but-empty key.** `SPACES_PUBLIC_BUCKET=` is the natural way to say "no second bucket" and is what `.env.example` ships, so the public disk got a bucket named `''`. Use `env('A') ?: env('B')`.
3. **A configurable storage location needs *both* sides changed.** `snack.avatars.disk` was already configurable and the write side honoured it (`UpdateAvatar`, `UpdateWorkspaceLogo`), but `User::avatarUrl()` and `Workspace::logoUrl()` hardcoded `Storage::disk('public')` — so setting it uploaded to the bucket and then asked the browser for `/storage`, where nothing was. **When a storage location becomes configurable, grep for every place that also builds a URL for it.**
4. **`'throw' => false` hid all of the above.** Flysystem returns `false` from a failed write rather than raising: `put()` returned false, the row was still written, and the UI showed a file whose bytes were nowhere. Both Spaces disks now set `'throw' => true`. **When probing a disk, check `put()`'s return value — a try/catch alone proves nothing**, and that is how this was nearly missed twice in one session.

**`throw => true` has a counterpart: delete must stay best-effort.** `DeleteUpload` always documented that its disk-delete failure "is ignored on purpose" — resting entirely on `throw => false`. Turning throwing on inverted it, so an unreachable bucket, or a row whose bytes never landed, became *undeletable* — exactly the wreckage a bad endpoint leaves behind. The swallow is now explicit in the action (logged, not silent). **Write wants to be loud; delete wants to be quiet. Never let one config flag decide both.**

## The suite could write to the real bucket

Existing tests fake storage *by disk name* (`Storage::fake('local')`), so once a developer's `.env` had `SNACK_UPLOAD_DISK=spaces` the application wrote straight past the fake into live DigitalOcean — one `pest` run put 11 objects in the bucket, including files named `.env`, while the assertions failed against an empty fake. On CI with those credentials this fills the bucket every run.

`phpunit.xml` now pins `SNACK_UPLOAD_DISK=local`, `SNACK_AVATAR_DISK=public`, `FILESYSTEM_DISK=local` and blanks the `SPACES_*` credentials — the same containment `MAIL_MAILER=array` already gives the mailer. **Consequence: any test asserting on Spaces config must build its own environment and re-`require` the config file, because the ambient values are deliberately blank.**

## Migrating what is already stored

Flipping the env decides where the *next* file goes. `snack:sync-storage` moves the rest (`--dry-run`, `--prune`, `--attachments`, `--avatars`, `--from=`).

Attachments survive a switch unaided because each row records its own `disk`. **Avatars and logos do not** — there is no disk column, the URL is rebuilt from `snack.avatars.disk` on every render, so flipping it instantly points every existing face at a disk its bytes are not on. Run the sync in the same deploy as the env change.

The command streams rather than `get()`-ing (a 50 MB video would sit in memory), verifies byte counts on the destination before `--prune` will delete anything, and repoints a row's `disk` column only once *every* object for it — original *and* thumbnail — has landed; a row claiming the new disk with its thumbnail still on the old one renders broken with nothing left to say where the bytes are.

## Diagnosing a server

Dump each disk's `bucket` / `endpoint` / `throw`, then `put()` → **check the return value** → `exists()`. And production almost certainly runs `config:cache`, so an `.env` edit does nothing until `php artisan config:clear && php artisan config:cache` — that alone explains a "I changed it and nothing happened".

See [[snack-asset-library]], [[snack-local-serving]], [[snack-test-and-gate-traps]].
