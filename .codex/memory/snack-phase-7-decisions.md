---
name: snack-phase-7-decisions
description: "Snack Phase 7 optimization: the timeline is windowed with @tanstack/react-virtual but keeps the old scrollHeight-delta correction because the library's own anchoring is systematically short, plus the query-count invariant that found two real N+1s."
metadata: 
  node_type: memory
  type: project
  originSessionId: ab4c6e02-468d-4508-bfd9-0947137b8fce
  modified: 2026-08-01T13:45:49.333Z
---

Phase 7 (Optimization), done 2026-08-01. Browser-verified against a 400-message channel seeded and then deleted.

## Measure first — this codebase is already optimized

`MessageHistory::shareContainer()` already existed and already killed the worst N+1. A generic "audit for N+1s" checklist would have found nothing. What found real bugs was an **invariant**: serving 30 rows must cost the same number of queries as serving 3. `tests/Feature/Performance/QueryCountTest.php` applies it to seven list endpoints.

Assert the invariant, never a ceiling. A ceiling picked loose hides a genuine N+1; picked tight it breaks on any unrelated change to shared props. Five endpoints passed and locked in; two did not.

## The column subset that was both slow and wrong

Mentions and Saved eager-loaded `channel:id,name,slug,type,workspace_id`. Two separate faults:

- **N+1**: the policy consults the container's *workspace* and *membership*, neither loaded. 201 queries for 30 rows. `app/Services/MessageContainers.php` now primes the distinct container set once — 201 → 23, and flat. It exists separately from `MessageHistory` because a timeline has one container and these views span the workspace.
- **A live permissions bug**: `ChannelPolicy` decides on `is_archived`, `is_private`, `is_default`, none of them selected. **An unselected column reads back as `null`, not an error**, so an archived channel described itself as live and both views offered actions the server would refuse. The subset saved nothing either — `ContainerSummary` picks its own fields.

**Never eager-load a container with a column subset when a policy will inspect it.** Load the row.

## Windowing the timeline: what the library gets wrong here

`@tanstack/react-virtual` (user-approved dependency), windowed by **padding above and below**, not absolute positioning — absolute takes rows out of flow and kills the sticky day dividers, which need a normal-flow box.

Three of its conveniences do not survive contact with this timeline:

- **`anchorTo: 'end'` is systematically short.** Anchoring by row identity cannot see the rows either side of the join changing shape. Measured a *constant* 82–95px hop per page loaded. The fix is the pre-virtualization correction, put back on top: `scrollTop = anchorTop + (scrollHeight - anchorHeight)`. The estimate objection written into the original docblock turns out not to bite — rows outside the window keep their estimate rather than being measured, so both height readings are the same kind of number. **Drift went to 0px.**
- **`followOnAppend` scrolls to the end of the *window*, not the timeline.** Unsent messages and the bottom padding sit below it in ordinary flow, so a message you just sent landed ~1.5 rows below the fold. Set it `false` and follow against the container's own `scrollHeight`.
- **The React Compiler refuses to compile the component.** `useVirtualizer` returns functions it cannot prove safe to memoize, so it bails and says so as a lint warning. Expected, documented in the file, not an oversight. No `memo()` was added — nothing else in the codebase uses it, and the expensive per-row work is still compiled on its own.

## The day divider was lying

The oldest loaded message always rendered a day divider, because `previous === null` was read as "starts a day". It means *the previous one is not loaded yet*. So the divider asserted a day began where it didn't, and when that page arrived the divider vanished and the row lost ~50px **above the reader**. `startsDay` for the first row is now `!hasMore`. This was a pre-existing content bug that windowing merely made visible.

## Phase 7.3 (bundle splitting) is REVERTED — do not redo it blindly

Lazy-loading the three layouts in `app.tsx` measured beautifully: **219 KB gz across 49 chunks → 116 KB gz across 5**, because the workspace shell drags in Pusher (~22 KB gz) that a signed-out visitor never uses. It also **blanked `/login` about a second after paint, silently**. A stashed-baseline control confirmed the fault was the change, not pre-existing. It was not the prefetch — it blanks without it. Root cause never found; stopped rather than guess further.

One thing learned on the way that outlives the revert: **Inertia's `layout()` resolver runs inside React's render pass**, once per render and twice under StrictMode. Anything that reaches out of it — starting a fetch, warming a chunk — is a side effect during render, and it fails by abandoning the render and leaving a blank page with **nothing in the console**.

## Gate

568 Pest (+9), 229 Vitest (+4), Pint, PHPStan 7, tsc, Prettier clean; ESLint 0 errors and the 1 expected React Compiler warning.

See [[snack-phase-status]], [[snack-local-serving]], [[snack-test-and-gate-traps]], [[snack-container-abstraction]].
