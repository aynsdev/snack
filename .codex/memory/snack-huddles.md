---
name: snack-huddles
description: "Huddles (live audio/video) on self-hosted LiveKit: the JWT is hand-rolled to avoid a dependency, one live huddle per container is a partial unique index, and the media server is a separate authorization boundary Laravel never sees."
metadata: 
  node_type: memory
  type: project
  originSessionId: ecb07129-5357-4c4a-88b8-e6b76c9815d1
  modified: 2026-08-04T02:45:58.540Z
---

Built 2026-08-03/04. Live audio/video attached to a channel or DM, carried by a **self-hosted LiveKit** (Apache 2.0) brought up by `docker-compose.livekit.yml` + `livekit.yaml` at the repo root. No audio or video touches a third party.

## LiveKit is a second authorization boundary, and Laravel is not in front of it

The browser dials the media server **directly** with a JWT signed by the shared API secret. LiveKit verifies the signature, reads the permissions out of the token, and honours them — nothing downstream gets a second opinion. So `App\Services\LiveKit\TokenIssuer` *is* the entire authorization for huddle media, and every caller must already have run `HuddlePolicy`.

Consequences that shaped the design:

- **Room names are ULIDs** (`huddles.public_id`), never `channel-7`. Anyone able to mint a token for their own huddle could otherwise dial into someone else's — the room name is the only thing scoping the grant.
- **The token withholds `roomAdmin`, `roomCreate`, `roomList`, `roomRecord`, `ingressAdmin`.** `roomJoin` on a named room implicitly creates it; the rest would let any participant enumerate or evict everyone.
- **Token `metadata` is broadcast to every other participant** without passing through any Snack authorization on the way out, so it carries only `user_id`, `username`, `avatar_url`.
- **`nbf` is backdated 60s.** The media server is a separate container with its own clock; a token minted "now" against a clock a few seconds behind is *not yet valid*, and LiveKit's refusal says nothing about clocks.
- **Identity is `user.{id}`.** LiveKit disconnects the older connection under a duplicate identity — which is the behaviour we want (joining from a phone takes the call off the laptop), and is why `JoinHuddle` also removes you from any other live huddle.

**The JWT is written by hand** (~40 lines of `hash_hmac` + base64url) rather than pulling the LiveKit PHP SDK: the token is the only part of that SDK this app would use, and a dependency here has to be trusted with the secret. Claim names are camelCase because the Go server unmarshals them that way — renaming any produces a token that verifies fine and grants nothing.

## The database decides who started it

`huddles_one_live_per_channel` / `_per_conversation` are **partial unique indexes** on `WHERE ended_at IS NULL`. Two people pressing Huddle in the same second is the ordinary case, not an edge case, and the application-level find-or-create races — producing two live huddles in one channel, both working perfectly, each person alone wondering why nobody can hear them. `JoinHuddle` therefore lets the insert fail and reads a violation as "somebody started it a millisecond ago". Same shape as [[snack-conversation-public-id]]'s sibling in `FindOrCreateConversation`.

`huddle_participants_one_live_per_user` does the same job for joining, which is what makes a double-clicked Join and a reconnecting client idempotent.

## Everything derives from the container

`HuddlePolicy` has no membership of its own — it delegates to the container's `post` right (not `view`), because joining puts your voice in the room and that is a contribution. A private channel's huddle is invisible to a non-member automatically, and stays that way if the channel rules change. Consistent with [[snack-container-abstraction]].

`snack.huddles.enabled` (derived from `LIVEKIT_API_SECRET` being set) is checked **inside the policy** rather than in a controller, so the client's rendered affordance and the server's answer come from one place — the button disappears rather than dialling a server that is not there.

## Two events, two audiences

- `HuddleChanged` → the container's **presence channel**, on every roster change, carrying **full state**. Deltas would strand somebody in a call they left after one dropped frame. Same reasoning as [[snack-phase-4-decisions]]'s full-state broadcasts.
- `HuddleActivity` → members' own `user.{id}` channels, **only on start and end**. Fanning out to `workspace.{id}` would be cheaper but would leak the *timing* of activity in private channels.

Leaving is announced three ways (button, `pagehide` with `keepalive`, tab close) and none can be relied on, so `SweepHuddles` (every minute) closes huddles whose participants all vanished — with `empty_grace_seconds` so a page reload does not tear down a live call.

## Frontend

`HuddleProvider` is mounted in `workspace-layout.tsx`, **not in a page**: walking from #design to a DM mid-call is ordinary, and a `Room` owned by the channel page would be destroyed on that click. LiveKit's `Room` is an event emitter full of objects that mutate in place, so every relevant `RoomEvent` re-snapshots it into plain React state — reading the room during render gives React no reason to re-render when somebody unmutes.

Remote audio must be attached to real `<audio>` elements or it is never heard (LiveKit subscribes; the browser has nothing to play it through). They live in the provider, outside every page. The local participant's audio is deliberately **not** attached — that is an echo.

### An effect with no dependency array must be idempotent (found 2026-08-04)

`HuddleTile` and `RemoteAudio` both run their attach effect on **every** render, because a track appearing changes no value React can compare. The first version attached unconditionally and detached in the cleanup — so each render did `detach()` then `attach()`, and `detach()` nulls `srcObject`. With `ActiveSpeakersChanged` re-snapshotting several times a second while anyone speaks, that is a **camera that strobes for the whole call** and audio that stutters. Nothing errors; it just looks broken.

Fix: keep the bare effect, but hold the currently-attached track in a ref and return early when it is unchanged, with teardown moved to a separate mount-only effect calling `track.detach()` with no argument. `resources/js/components/snack/huddle/huddle-tile.test.tsx` pins it (verified to fail against the old implementation). **Any no-dep-array effect that touches a DOM element needs this shape** — the rule generalizes past huddles.

### Two things about video the browser will not tell you

- **The local camera must be mirrored** (`-scale-x-100`), and *only* the local camera. A self-view is a mirror or it feels wrong to look at. Remote video is already flipped by the sender's own browser before publishing, so flipping it again undoes it; a mirrored screen share is backwards text.
- **`HuddleStage`** (in `huddle-tray.tsx`) is a full-viewport overlay, with the real Fullscreen API as a button *inside* it rather than as the only mechanism — `requestFullscreen()` is refused without a gesture, hides the tab strip, and animates a whole desktop space on macOS. The tray is replaced rather than layered under, so there is exactly one hang-up button on screen.

### Spotlight, and the two traps in it

One participant can be focused (big tile + filmstrip); a share is unreadable in an even grid, which is the whole reason to look at one. Escape unwinds **one layer at a time** — browser fullscreen, then spotlight, then stage.

- **`document.fullscreenElement` is `undefined`, not `null`, wherever the API is absent** (jsdom, older Safari, a permissions-denied iframe). `!== null` therefore reads those environments as *already fullscreen* and silently swallows the Escape handling that defers to it. Normalized through a `fullscreenElement()` helper returning `?? null`. This is why the first version of the tray tests all failed.
- **Auto-focusing a new screen share must diff against the previous sharers**, held in a ref — not "focus whoever is sharing". Keying the effect on a joined identity string is not enough: when a *second* person starts sharing the key changes, and a naive implementation then spotlights the first sharer, whose share everybody already dismissed. `huddle-tray.test.tsx` pins this (verified to fail against the naive version; the simpler "stays out once you left it" test does *not* distinguish them, because the dep array alone covers that path).

## Call notifications alert in *both* directions (added 2026-08-04)

`HuddleActivity` was broadcast from day one and **nothing on the client listened to it** — the "sidebar dot" its docblock describes was never built, so a huddle starting while you read another channel was silently invisible. `useHuddleSync` / `HuddleSync` (mounted in `workspace-layout.tsx`, **inside `HuddleProvider`** so its Join button reaches the real connection) now consumes it.

The rule deliberately inverts `useChannelSync` and `useConversationSync`, which raise a banner *only* when `pageIsUnattended()`. A message can wait and the sidebar badge is answer enough; a call cannot, and missing it means the conversation went ahead without you. So:

- **Attended tab** → in-app sonner toast, `duration: Infinity`, carrying a **Join** action. This is the case the two message notifiers have no answer for at all. Raised even for the container already on screen — the huddle bar is a quiet strip under the header, and quiet is the wrong register for a ringing phone.
- **Unattended tab** → OS banner with `requireInteraction: true`. It **navigates rather than joins**: an OS notification is clicked from muscle memory as often as from intent, and the cost of that here is a live microphone in a room nobody meant to enter. (The toast's explicit Join button is a deliberate click, so it may join directly.)
- Sound plays for both — the only signal that reaches somebody in another app with the tab still technically visible.

**Both surfaces are withdrawn when `is_live: false` arrives**, tracked in a `Map` keyed by huddle id. An invitation to a call that ended ten minutes ago is a promise the app cannot keep — and clicking it would not even error, it would silently *start a new huddle*. `duration: Infinity` is only safe because every end path (last leave, explicit end, `SweepHuddles`) routes through `EndHuddle` → `AnnounceHuddle::lifecycle()`.

`showNotification()` grew `requireInteraction` and `audible` options for this; `silent` was previously hardcoded true.

**How to apply:** treat the media server as untrusted-inbound and un-fronted-outbound. Any new capability goes in the token grant, and the grant is the only thing enforcing it. See [[snack-insecure-context-apis]] for why huddles do nothing at all on `snack.test`.
