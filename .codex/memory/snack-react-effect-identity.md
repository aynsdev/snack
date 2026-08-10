---
name: snack-react-effect-identity
description: "Snack's recurring React trap: a hook effect keyed on an object the caller rebuilds each render fetches in an infinite loop — plus why the obvious regression test passes anyway."
metadata: 
  node_type: memory
  type: project
  originSessionId: 5a518eb0-5078-4fe3-b614-445ac63f17a4
  modified: 2026-08-05T14:36:57.805Z
---

Found and fixed on 2026-07-31, in Phase 4's `@`-mention picker. Expect it again in Phase 5, where search-as-you-type has exactly this shape.

## The bug

`useMentionCandidates` keyed its `useEffect` on a `source` **object**. Both `channels/show.tsx` and `conversations/show.tsx` built that object as an inline literal, so it had a fresh identity on every render. The cycle:

effect runs → debounced fetch → `setRemote(...)` → render → caller rebuilds the object → deps changed → effect re-runs → …

The `@`-picker hit `/mentionable` roughly 8× a second for as long as it stayed open. **Nothing errored.** No console message, no failed request — only a flood in the network tab, which is invisible unless it happens to be open.

**Fix, both halves kept:**
1. Key the effect on the *resolved URL string* — the only thing that actually changes what to request — not the object.
2. `useMemo` the object in the page. This is already the codebase convention: `use-channel-messages.ts` memoizes `endpoints` for precisely this reason, with a comment saying so. Follow it for anything object-shaped handed to a hook.

Audited the rest of Phase 4 and it was the only instance. `useTyping` is safe because `@laravel/echo-react`'s `useChannel` returns a `useMemo`'d object *and* registers its own effect before the caller's, so `channel()` is already non-null. `usePins`, `useThread`, and `useReactions` key on strings or stable `useCallback`s.

## The testing lesson, which is the more transferable half

**The first regression test passed against the broken code.** Each loop cycle costs one 120 ms debounce interval, so an assertion running immediately sees only the first request and reports success. The bug only appeared after waiting ~500 ms: 5 calls and climbing.

For anything debounced, throttled, polled, or retried, a "it only happened once" assertion is meaningless unless several intervals have elapsed. Write the wait, and comment *why* it is there, or someone removes it as dead weight and the test silently stops testing anything. The kept test says this in a docblock.

**Why:** this whole class of failure is silent in exactly the way [[snack-local-serving]] warns about — a dead socket, a stale worker, a cached bundle, and a request flood all look identical to "the feature is fine" or "the feature is broken", depending on where you happen to be looking.

**How to apply:** before writing a hook effect, check every dependency that is not a string or number, and ask who constructs it and how often. Before trusting a timing-related green test, make it fail on purpose first.

## The sibling trap: `useState(prop)` never re-reads the prop

Found in Phase 6's `useNotifications`, same family, opposite direction. A hook seeded with `useState(initial)` where `initial` is an Inertia prop is **frozen at first render**. "Mark all read" redirected back, the server returned every row stamped read — and the list carried on rendering the stale copy. The badge cleared while the rows stayed bold, so the server looked broken when it was not.

Fix is React's documented "adjust state when a prop changes": compare **during render**, not in an effect, so the reset lands in the same commit instead of painting the stale list first.

```
const [serverCopy, setServerCopy] = useState(initial);
if (serverCopy !== initial) { setServerCopy(initial); setNotifications(initial); }
```

Comparing by **identity** is load-bearing: a full Inertia page response gives the prop a new identity and resets, while `router.reload({ only: ['snack'] })` leaves it untouched — so a live WebSocket arrival is not thrown away by the badge refresh that follows it. Both behaviours have a test.

Any hook in Snack taking an Inertia prop as its seed has this shape. Check the ones that also mutate locally.

## The third trap: a child's effect runs before its parent's

Found 2026-08-05. Unread badges never cleared when you opened a channel or a DM.
`useMessageTimeline` recorded "the newest id displayed" in a **ref written by an
effect**, and `MessageList` — its *child* — called `markAsRead()` from its own
mount effect. React flushes child effects first, so the callback always ran a
beat before the value it needed existed, found `null`, and returned. No error,
no request: the POST simply never happened, and the badge sat there until the
next message arrived while the reader happened to be at the bottom.

Fix: derive the value **during render** (`messages.at(-1)?.id`) and put it in the
callback's dependency list. Anything a child may call on mount cannot depend on
a parent effect having run. A ref that only an effect writes is the tell.

The regression test has to reproduce the *ordering* — render a child that calls
the callback from `useEffect` — because calling it from the test body passes
against the broken code (effects have already flushed by then).

Same fix landed a second half: reaching the bottom by **scrolling** now marks
read too. Previously the pointer only moved when the newest id changed, so
someone who scrolled up through unread history and back down kept their badge.
`markAsRead` drops a pointer it has already reported, which is what makes it
safe to call from every scroll event.

See [[snack-phase-4-decisions]], [[snack-phase-6-decisions]], [[snack-phase-status]], [[snack-timeline-query-cache]].
