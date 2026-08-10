---
name: snack-overlay-clipping
description: "Snack's UI rule: never hand-roll a popover — an absolutely-positioned overlay is clipped by any scrolling ancestor, and z-index cannot rescue it."
metadata: 
  node_type: memory
  type: project
  originSessionId: 5a518eb0-5078-4fe3-b614-445ac63f17a4
  modified: 2026-08-07T03:44:22.525Z
---

Found on 2026-07-31, browser-verifying Phase 4. The user reported the reaction picker with "some parts hidden".

## The rule

**An absolutely-positioned overlay is clipped by any ancestor with `overflow` set, no matter how high its z-index.** z-index orders elements within a stacking context; it does not lift one out of an overflow box. Snack's message timeline is `overflow-y-auto`, so *every* overlay anchored to a message — reaction picker, message actions, future file or search menus — hits this.

The emoji picker was written as `absolute top-full right-0 z-50` inside a message. For any message near the viewport edge it was cut in half, and it looked fine while the list was short, which is why it survived the whole build.

**Fix, and the standing rule: use the project's existing portalled primitive.** `components/ui/dropdown-menu.tsx` wraps Radix, which portals to the body and flips side on collision automatically. Switching to it also deleted a hand-rolled click-outside backdrop that existed only to compensate. Snack also has `dialog`, `sheet`, `tooltip`, and `popover`-adjacent primitives already installed.

This is `CLAUDE.md`'s "check for existing components to reuse before writing a new one" — the instruction was there, and hand-rolling it anyway cost a user-visible bug. Check `components/ui/` before building any floating element.

**Related layout fact:** the workspace shell's `<main>` does not scroll (`h-dvh overflow-hidden`). Every page inside it brings its own `overflow-y-auto` region — that is why the timeline scrolls and why `SettingsLayout` had to grow one. See [[snack-phase-4-decisions]].

**Why:** the failure is invisible in tests — jsdom has no layout, so nothing about clipping is observable — and invisible in a short list. Only a real browser with content near an edge shows it.

**How to apply:** before writing `absolute` on anything that floats above content, ask what scrolls between it and the body. If anything does, portal it.

## The mirror image, found 2026-08-07: absolute children *escaping* a scroller

The user reported two horizontal scrollbars on the board — one under the columns (correct) and one across the whole window (wrong). Cause: **Tailwind's `sr-only` is `position: absolute`**, and an absolute element resolves against its nearest *positioned* ancestor — with none, that is the page. Every screen-reader label inside the board's `overflow-x-auto` strip (card buttons, the column ⋯ menus, the add-column tile) was therefore anchored to the document, which grew as wide as the whole board: `documentElement.scrollWidth` 1934 against a 885px viewport, while `body.scrollWidth` stayed 885 — the tell that the overflow is coming from something whose containing block is the initial one.

**Fix: `relative` on the scroller itself** (`pages/projects/board.tsx`, and the releases table wrapper, which had it too). One class makes it the containing block for every such descendant, and its own overflow then clips them.

**How to spot it:** `de.scrollLeft = 400; de.scrollLeft` — if it stays non-zero the page really scrolls sideways; then list elements with `getBoundingClientRect().right > innerWidth` and check which are `position: absolute`. Any `overflow-x-auto` container holding buttons with `sr-only` labels needs `relative`.

Also learned the hard way, twice, in the same session: a `{/* … */}` JSX comment **cannot** sit next to the element inside a ternary branch — `cond ? (…) : ( {/* … */} <div/> )` is two expressions in one slot and fails to parse. Use a plain `/* … */` comment inside the parens.

See [[snack-local-serving]] for the browser-verification traps that hide bugs like this.
