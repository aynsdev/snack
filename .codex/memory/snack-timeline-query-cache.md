---
name: snack-timeline-query-cache
description: "Message timelines live in the TanStack Query cache, not useState — one infinite query per container, written by the socket; plus the two traps that migration hit."
metadata: 
  node_type: memory
  type: project
  originSessionId: 57b241c1-93a1-462b-9c04-e0bd386c6090
  modified: 2026-08-04T04:40:27.178Z
---

As of 2026-08-04 `useMessageTimeline` keeps a container's messages in the
TanStack Query cache: one `useInfiniteQuery` per container keyed by its
broadcast channel (`lib/query-keys.ts`), page 0 newest, older pages after.
Arrivals, edits, deletions, and reactions are written with `setQueryData`; only
optimistic *pending* sends stay in component state, because they have no id.
`lib/query-client.ts` disables every automatic refetch — the socket is what keeps
a timeline fresh, and a refetch would race the optimistic state.

**Why:** the pages now survive the hook unmounting, so walking channel → DM →
back returns the reader to the history they had scrolled through instead of the
newest page and a spinner. There is still exactly one copy of the list.

**How to apply:**
- The server-rendered first page arrives as an Inertia prop, and `initialData` is
  ignored once a cache entry exists — so an effect folds it in. **Key that effect
  on a content signature, never on the prop object.** A page that builds props
  inline rebuilds them every render, and the effect then restored page 0 on every
  render, silently undoing each socket arrival one render later. Same family as
  [[snack-react-effect-identity]].
- Flattening de-duplicates by id and sorts ascending, which is what makes the
  merge safe when the fresh page and the cached page overlap.
- Cache notifications are **batched asynchronously**, so a synchronous `act()`
  sees the pre-write render and the assertion fails with no error. Tests set
  `notifyManager.setScheduler((cb) => cb())` in `resources/js/tests/setup.ts`;
  the alternative was a `waitFor` around every existing assertion. Anything
  rendering a timeline needs a `QueryClientProvider` — use `withQueryClient()`
  from `resources/js/tests/query.tsx`, which builds one client per call so a
  deliberately long-lived cache cannot leak between tests.
- Reading `ref.current` during render is rejected by lint (`react-hooks/refs`);
  the newest-seen pointer is maintained in an effect instead.
- Opening a timeline scrolls with `virtualizer.scrollToEnd()` *and then* pins
  `scrollTop = scrollHeight` on the next frame: the virtualizer's end is the end
  of its window, which sits above the list's bottom padding, so on its own it
  stops short of the newest message. See [[snack-phase-7-decisions]] for the
  scroll-anchoring background.
