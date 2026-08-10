---
name: snack-navigation-shell
description: "The four-column shell — workspace rail, section rail (Home/DMs/Activity/Saved/Files/Assets/Tasks), a sidebar whose shape changes per section, then content."
metadata: 
  node_type: memory
  type: project
  originSessionId: 57b241c1-93a1-462b-9c04-e0bd386c6090
  modified: 2026-08-04T05:35:45.829Z
---

As of 2026-08-04 the workspace shell has a second rail between the workspace
switcher and the sidebar: `components/snack/nav-rail.tsx`, holding Home, DMs,
Activity, Saved, Files, and two announced-but-unbuilt items (Assets, Tasks).
Every destination is `/w/{workspace}/…` and the rail only renders inside the
workspace shell, so it switches with the workspace.

The sidebar is no longer one fixed list:

- **Home** (workspace root or any `/channels…`) — Threads, Huddles, channels,
  then the DM roster.
- **DMs** (`/dms…`) — the DM list alone, no channels. `WorkspaceSidebar` returns
  a different tree for this; the shared list is `DirectMessageSection`.
- **Activity** (`/activity`, and the standalone `/notifications`, `/mentions`,
  `/threads`) — `WorkspaceLayout` renders **no** sidebar at all.

**Why:** the sidebar lists *things* that change as you join and leave them; the
rail lists the parts of the product, which do not. Mixing them made every new
destination lengthen the list you navigate rooms by.

**How to apply:**
- Which section is current is decided by URL regex in `hooks/use-active-channel.ts`
  (`useIsHomeView`, `useIsDirectMessagesView`, `useIsActivityView`,
  `useIsHuddlesView`). Activity deliberately matches the three pages it gathers,
  so reaching Threads from the sidebar does not darken the section it lives in.
  Boundaries are tested in `use-active-channel.test.tsx` — `/dmsomething` must
  not read as DMs, since the sidebar *changes shape* on that answer.
- **Except a conversation**, which is not URL-derivable: `/dms/{id}` is the same
  URL whether it was opened from the DM list or from the roster at the bottom of
  the channel sidebar, and deriving from it meant a click in that roster replaced
  the sidebar it was clicked in. Since 2026-08-04 both the sidebar shape and the
  rail's pill read `hooks/use-sidebar-section.ts`: a conversation *inherits* the
  section (kept in `sessionStorage`, per-tab, so a reload holds), and only
  entrances set it. The rail's DMs item must call `enterSection` on click,
  because `GET /dms` redirects into the newest conversation — the destination
  lands on the very URL that inherits, so without the declaration the DM sidebar
  is unreachable.
- The search slot is section-specific. In DMs it is a **filter box**, not the
  global search button: it narrows the roster already on the shared prop as you
  type, with no request and no navigation. Global search asks a different
  question — what people *wrote* — so when nothing matches, the empty state
  offers `search?q=` rather than silently redirecting there. ⌘K is mounted by
  `WorkspaceSidebar` itself so it survives the shape that has no search button.
- `GET /dms` redirects into the most recently active conversation and only
  renders `conversations/index` when there is nothing to open. It must never
  create a conversation to have something to redirect to: a crawler or a
  prefetch would then start threads nobody chose. See [[snack-dm-roster]].
- The rail's surface is a real theme token, `--nav` (one step lighter than
  `--rail`, defined in all 12 theme blocks of `app.css`), plus `border-x`. A
  translucent overlay over `--rail` was rejected: it shifts with whatever the
  theme puts behind it.
- Activity's tab lives in `?tab=`, and the controller builds **only** that tab's
  list — `null` means "not this tab", `[]` means "nothing to show", and the empty
  states depend on the difference. `ActivityFeed` is the single source for all
  three lists so a tab and the standalone page mirroring it cannot drift.
- Huddles history (`/huddles`) is scoped to containers the viewer is *in*, not
  the workspace: the participant list of a DM huddle would otherwise say who is
  talking to whom. See [[snack-huddles]].
