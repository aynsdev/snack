---
name: snack-dm-roster
description: "The sidebar DM list is the workspace roster, not a list of threads — people come from `snack.current.people`, and the conversation is created by the click that opens it."
metadata: 
  node_type: memory
  type: project
  originSessionId: 57b241c1-93a1-462b-9c04-e0bd386c6090
  modified: 2026-08-05T14:36:45.952Z
---

As of 2026-08-04 the sidebar's "Direct messages" section lists **every active
member** of the workspace, not the viewer's conversations. Each row is a
`WorkspacePerson` — `{ user, conversation }` — built by
`WorkspaceNavigator::sidebarPeople()` and shipped on the shared `snack` prop
next to `conversations`, which stays the *complete* set because huddles,
container labels, and unread lookups all resolve a conversation by id against
it. The sidebar renders groups from `conversations` and directs from `people`;
`useSidebarConversations()` exposes `groups`/`mutedGroups` for exactly that
split.

**Why:** listing conversations meant a colleague only appeared once a thread
with them existed, so seeing the team in the sidebar required creating a DM row
per member — the thing the user asked to stop doing.

**How to apply:**
- A person with no thread renders as an Inertia `<Link method="post">` to
  `conversations.store` (find-or-create). Never a GET, and never with
  `prefetch` — a prefetched GET would create a conversation on hover, which is
  the original bug wearing a different hat.
- Guests get `people => []`, matching `DirectoryController`: they may not start
  conversations, so the roster would be a back door to the member directory the
  picker refuses them. Their existing conversations still ship.
- Ordering is server-side and is **name only** (2026-08-05). It used to lift
  recently-active threads to the top; that made a row jump out of the alphabet
  on every message sent or received, so the sidebar re-sorted under the reader's
  cursor and the user reported it as "keeps rearranging". `sortPeople()` is
  gone; the roster query's `orderBy('users.name')` is the whole order. The
  unread badge carries "this one wants you" without moving anything. Pinned by
  "keeps the roster in one order however busy a thread gets" in
  tests/Feature/Conversations/SidebarPeopleTest.php. Group conversations, which
  are real threads rather than a directory, still order by recency.
- A muted *direct* message stays on its person's row with the bell icon; only
  muted groups move to the collapsed Muted section, or a member vanishes from
  "all the members" because of a notification setting.
- Bounded by `snack.conversations.people_limit` (100). Past that the
  new-message picker's search is the way in.
- The roster costs two fixed queries; the conversation join is eager-loaded and
  guarded by "serves the DM roster in a constant number of queries" in
  tests/Feature/Performance/QueryCountTest.php — verified to fail (84 vs 30
  queries) with the eager loads removed. See [[snack-container-abstraction]] and
  [[snack-conversation-public-id]].
