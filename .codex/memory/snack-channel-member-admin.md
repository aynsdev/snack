---
name: snack-channel-member-admin
description: "Adding other people to a channel is a separate endpoint from joining one, and the mobile shell opens on the sidebar via an entered_workspace flash."
metadata: 
  node_type: memory
  type: project
  originSessionId: 0a3556e7-3182-4c49-9f03-7fb3f7991a7b
  modified: 2026-08-04T05:09:01.559Z
---

Two pieces built 2026-08-04 on `feature/messaging`, after Phase 7.

**Adding other people to a channel.** `ChannelPolicy::manageMembers` had existed since Phase 1 with **no caller** — there was no way to put anyone into a private channel, which made private channels unusable for anyone but their creator. Now: `POST w/{workspace}/channels/{channel}/members` → `ChannelMemberController::store` → `AddChannelMembers`.

- It is a **separate route** from `channels.membership.store` (self-join) on purpose. Joining is gated on `join`, adding on `manageMembers`; one route branching on whose id is in the body would make the authorization depend on request data.
- `AddChannelMembers` is not a loop over `JoinChannel`: the system message names everyone in one sentence, and only genuinely-new members are announced. Re-adding an existing member is a silent no-op with an `error` flash, **not** an error status.
- Audit entries are written in the controller, never the action — `JoinChannel` is also the automatic-enrolment path for default channels, so recording inside it would bury deliberate changes. Same reasoning as `ChannelMembershipController`.
- `ChannelMembershipGranted` broadcasts to the added users' own `user.{id}` channels. `ChannelCreated` cannot do this job — it fans out to the whole workspace, which is exactly what a private channel must not do. New events that broadcast must be added to the `Event::fake([...])` list in `tests/Pest.php` or every test touching them 500s on a real Reverb call.

**Mobile entry lands on the sidebar.** `WorkspaceController::show` redirects into a landing channel, which on a phone meant opening the app inside one arbitrary room with no visible way out. The redirect now flashes `entered_workspace`, shared through `HandleInertiaRequests` as `flash.entered_workspace`, and `WorkspaceLayout` opens its drawer when that is true **and** the viewport is mobile. Gated on the flash rather than the URL because the channel page is the same page whether the server chose it or a shared link did — a link to a conversation must open that conversation. The drawer is now `w-full max-w-none sm:max-w-none` (the sheet's own `w-3/4`/`sm:max-w-sm` are sized for a drawer beside content); because a full-width drawer cannot be dismissed by tapping outside, the sheet's built-in close button is load-bearing, and `WorkspaceSidebar` takes an `inDrawer` prop only to pad its header clear of it.

See [[snack-navigation-shell]], [[snack-container-abstraction]], [[snack-test-and-gate-traps]].
