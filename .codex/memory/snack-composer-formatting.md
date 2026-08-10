---
name: snack-composer-formatting
description: "Snack's composer toolbar writes plain markers into the textarea — no rich text; plus the caret traps (blurred textarea reports 0) and the grammar pairing that any new button must respect."
metadata: 
  node_type: memory
  type: project
  originSessionId: 0cd228f2-747b-46c6-b837-68355c3252c0
  modified: 2026-08-04T06:12:14.014Z
---

The message composer (`resources/js/components/snack/message-composer.tsx`) has a Slack-shaped shell — formatting row, textarea, action row, all inside one bordered box — built on 2026-08-04. What is not obvious from reading it:

- **It is not a rich-text editor and must not become one.** Every button calls `applyCommand` in `lib/composer-format.ts`, which returns `(text, selection)` and writes the same marker a person could type. The message body stays one piece of author source text from keystroke to database, which is what keeps `dangerouslySetInnerHTML` out of the render path. See [[snack-spec-constraints]].
- **The toolbar may only offer what the grammar reads.** There is no underline button because `lib/message-format.ts` has no underline syntax; a button for it would write characters that render as themselves. Adding a button means adding the block/inline node *and* its renderer in `message-text.tsx` first — that is what ordered/bulleted lists needed.
- **A blurred textarea reports a caret of 0, not its last one.** Opening the emoji menu moves focus, so reading `selectionStart` on the next click inserted at the very start of the message. The fix is a `lastSelection` ref fed by the textarea's `onSelect` (React fires it for caret moves, not only for selections) plus an `activeElement` check. Any new control that acts on the selection has to go through `selectionOf`.
- **Selection restoration is a `useLayoutEffect`, not a `requestAnimationFrame`.** With rAF, two quick presses of the same button race — the second reads a stale selection — and a test that clicks twice fails. The layout effect runs in the same commit that wrote the value. (The older `choose` path for mentions still uses rAF and works because a pick is never doubled.)
- Toolbar and action buttons cancel their own `mousedown` so the caret never leaves the textarea. The emoji menu is a controlled `DropdownMenu`, because the glyphs are plain buttons rather than menu items and a plain button inside the panel does not dismiss it.

**Why:** each of these was a bug found in the browser or by a test, not a preference.

**How to apply:** before adding a composer control, decide what marker it writes and confirm the parser and renderer already read it; route it through `selectionOf` and `commit`.

See [[snack-overlay-clipping]], [[snack-react-effect-identity]].
