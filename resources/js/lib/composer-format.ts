/**
 * The text edits behind the composer's formatting toolbar.
 *
 * Snack stores what the author typed (see App\Services\MessageBody), so the
 * toolbar is not a rich-text editor — it writes the same markers a person could
 * have typed by hand, into the same plain textarea. That keeps one source of
 * truth for a message body and means the toolbar can never produce something
 * {@see parseMessage} does not understand.
 *
 * Every function here is a pure `(text, selection) → (text, selection)`. The
 * selection comes back because a formatting button that loses the caret is a
 * button you press once: after bolding a word the author is still mid-sentence,
 * and the text they had highlighted has to stay highlighted so a second press
 * can undo it.
 *
 * The commands only cover markers the grammar actually has. There is no
 * underline button because there is no underline syntax — offering one would
 * write characters that render as themselves.
 */

export type Selection = { start: number; end: number };

export type Edit = { value: string; selection: Selection };

export type FormatCommand =
    | 'bold'
    | 'italic'
    | 'strike'
    | 'code'
    | 'ordered'
    | 'bullet'
    | 'quote'
    | 'codeblock';

/** The inline commands, and the marker each one wraps a selection in. */
const MARKERS: Record<'bold' | 'italic' | 'strike' | 'code', string> = {
    bold: '**',
    italic: '_',
    strike: '~~',
    code: '`',
};

type LineKind = 'ordered' | 'bullet' | 'quote';

/**
 * Any line marker, used when switching one for another.
 *
 * Turning a bulleted list into a numbered one is a single press, so adding a
 * prefix strips whatever was already there rather than stacking "1. - item".
 */
const ANY_PREFIX = /^(?:>\s?|[-*] |\d+\.\s)/;

const OWN_PREFIX: Record<LineKind, RegExp> = {
    ordered: /^\d+\.\s/,
    bullet: /^[-*] /,
    quote: /^>\s?/,
};

/**
 * Apply a toolbar command.
 */
export function applyCommand(
    value: string,
    selection: Selection,
    command: FormatCommand,
): Edit {
    switch (command) {
        case 'bold':
        case 'italic':
        case 'strike':
        case 'code':
            return toggleInline(value, selection, MARKERS[command]);

        case 'ordered':
        case 'bullet':
        case 'quote':
            return toggleLinePrefix(value, selection, command);

        case 'codeblock':
            return toggleCodeBlock(value, selection);
    }
}

/**
 * Wrap the selection in a marker, or unwrap it if it is already wrapped.
 *
 * With nothing selected this leaves the caret between the two markers, so
 * pressing bold and typing produces bold text — the same thing the button does
 * to a selection, for someone who reaches for it before writing the word.
 */
export function toggleInline(
    value: string,
    selection: Selection,
    marker: string,
): Edit {
    const { start, end } = selection;
    const selected = value.slice(start, end);
    const width = marker.length;

    // Markers inside the selection: the author highlighted "**word**".
    if (
        selected.length >= width * 2 &&
        selected.startsWith(marker) &&
        selected.endsWith(marker)
    ) {
        const inner = selected.slice(width, -width);

        return {
            value: value.slice(0, start) + inner + value.slice(end),
            selection: { start, end: start + inner.length },
        };
    }

    // Markers just outside it: the author highlighted "word" of "**word**",
    // which is what a double-click gives them.
    if (
        start >= width &&
        value.slice(start - width, start) === marker &&
        value.slice(end, end + width) === marker
    ) {
        return {
            value:
                value.slice(0, start - width) +
                selected +
                value.slice(end + width),
            selection: { start: start - width, end: end - width },
        };
    }

    return {
        value:
            value.slice(0, start) +
            marker +
            selected +
            marker +
            value.slice(end),
        selection: { start: start + width, end: end + width },
    };
}

/**
 * Add or remove a line marker across every line the selection touches.
 *
 * Lists and quotes are per line, so the edit works on whole lines even when the
 * selection is a caret in the middle of one — nobody selects a line precisely
 * from column zero before pressing a list button.
 */
export function toggleLinePrefix(
    value: string,
    selection: Selection,
    kind: LineKind,
): Edit {
    const [blockStart, blockEnd] = expandToLines(value, selection);
    const lines = value.slice(blockStart, blockEnd).split('\n');
    const owns = (line: string) => OWN_PREFIX[kind].test(line);

    /*
     * Only remove when every line that could carry the marker already does.
     * Pressing the button over a half-marked selection finishes the job, which
     * is the reading that lets a second press undo it.
     */
    const marked =
        lines.some(owns) &&
        lines.every((line) => line.trim() === '' || owns(line));

    // A blank composer is the one case where a blank line should still get the
    // marker: the author is asking to start a list, not to skip one.
    const allBlank = lines.every((line) => line.trim() === '');

    let number = 0;

    const next = lines.map((line) => {
        if (marked) {
            return line.replace(ANY_PREFIX, '');
        }

        if (line.trim() === '' && !allBlank) {
            return line;
        }

        number++;

        const prefix =
            kind === 'ordered'
                ? `${number}. `
                : kind === 'bullet'
                  ? '- '
                  : '> ';

        return prefix + line.replace(ANY_PREFIX, '');
    });

    return replaceBlock(value, selection, blockStart, blockEnd, lines, next);
}

/**
 * Fence the selected lines, or unfence them when they already are.
 *
 * "Already fenced" has to include the case where the caret is simply sitting
 * inside a block — that is where it is while the author writes the code, and
 * pressing the button there has to turn the fence off rather than add a second
 * one around the one line they were on.
 */
export function toggleCodeBlock(value: string, selection: Selection): Edit {
    const lines = value.split('\n');
    const fence = fenceAround(
        lines,
        lineIndexAt(value, selection.start),
        lineIndexAt(value, selection.end),
    );

    if (fence !== null) {
        const [opener, closer] = fence;
        const kept = [
            ...lines.slice(0, opener),
            ...lines.slice(opener + 1, closer),
            ...lines.slice(closer + 1),
        ];
        const start = offsetOfLine(kept, opener);
        const inner = lines.slice(opener + 1, closer).join('\n');

        return {
            value: kept.join('\n'),
            selection: { start, end: start + inner.length },
        };
    }

    const [blockStart, blockEnd] = expandToLines(value, selection);
    const block = value.slice(blockStart, blockEnd);
    // The selection covering the fence lines themselves, rather than sitting
    // between them.
    const fenced = block.match(/^```[^\n]*\n([\s\S]*)\n```$/);

    if (fenced !== null) {
        return {
            value:
                value.slice(0, blockStart) + fenced[1] + value.slice(blockEnd),
            selection: {
                start: blockStart,
                end: blockStart + fenced[1].length,
            },
        };
    }

    const opener = '```\n';

    return {
        value:
            value.slice(0, blockStart) +
            `${opener}${block}\n\`\`\`` +
            value.slice(blockEnd),
        // Inside the fence, where the code goes — not after it.
        selection: {
            start: blockStart + opener.length,
            end: blockStart + opener.length + block.length,
        },
    };
}

/**
 * Replace the selection with literal text, leaving the caret after it.
 */
export function insertText(
    value: string,
    selection: Selection,
    text: string,
): Edit {
    const caret = selection.start + text.length;

    return {
        value:
            value.slice(0, selection.start) + text + value.slice(selection.end),
        selection: { start: caret, end: caret },
    };
}

/**
 * Open a mention at the caret.
 *
 * A space is added first when the caret is mid-word, because the tokenizer will
 * not read "ada@bo" as a handle — its lookbehind rejects an "@" that follows a
 * word character — and neither would anyone reading the message.
 */
export function insertMention(value: string, selection: Selection): Edit {
    const before = value.slice(0, selection.start);
    const needsSpace = before !== '' && !/\s$/.test(before);

    return insertText(value, selection, needsSpace ? ' @' : '@');
}

/**
 * The pair of fence lines the selection sits between, if it sits between one.
 *
 * Pairs are counted from the top of the message, the way the parser reads them,
 * so an unterminated fence at the end owns nothing and a selection that spans a
 * fence line belongs to no pair at all.
 */
function fenceAround(
    lines: string[],
    first: number,
    last: number,
): [number, number] | null {
    let opener: number | null = null;

    for (let index = 0; index < lines.length; index++) {
        if (!lines[index].startsWith('```')) {
            continue;
        }

        if (opener === null) {
            opener = index;

            continue;
        }

        if (opener < first && last < index) {
            return [opener, index];
        }

        opener = null;
    }

    return null;
}

/** Which line an offset falls on. */
function lineIndexAt(value: string, offset: number): number {
    let count = 0;

    for (let index = 0; index < offset; index++) {
        if (value[index] === '\n') {
            count++;
        }
    }

    return count;
}

/** Where a line starts, given the lines around it. */
function offsetOfLine(lines: string[], index: number): number {
    let offset = 0;

    for (let line = 0; line < index; line++) {
        offset += lines[line].length + 1;
    }

    return offset;
}

/**
 * The offsets of the whole lines the selection touches.
 */
function expandToLines(value: string, selection: Selection): [number, number] {
    const start = value.lastIndexOf('\n', selection.start - 1) + 1;
    const newline = value.indexOf('\n', selection.end);

    return [start, newline === -1 ? value.length : newline];
}

/**
 * Swap a block of lines for its rewritten form, and work out where the
 * selection ended up.
 *
 * A range stays a range over the whole block, so the author can see what they
 * changed and press again to change it back. A caret stays a caret, moved by
 * however much its own line grew or shrank — jumping it to the end of a
 * five-line quote would lose the place they were typing in.
 */
function replaceBlock(
    value: string,
    selection: Selection,
    blockStart: number,
    blockEnd: number,
    lines: string[],
    next: string[],
): Edit {
    const block = next.join('\n');
    const rewritten =
        value.slice(0, blockStart) + block + value.slice(blockEnd);

    if (selection.start !== selection.end) {
        return {
            value: rewritten,
            selection: { start: blockStart, end: blockStart + block.length },
        };
    }

    const offset = selection.start - blockStart;
    let consumed = 0;
    let caret = blockStart;

    for (let index = 0; index < lines.length; index++) {
        const lineEnd = consumed + lines[index].length;

        if (offset <= lineEnd) {
            const shift = next[index].length - lines[index].length;

            caret += Math.max(0, offset - consumed + shift);

            break;
        }

        // The +1 on both sides is the newline the split removed.
        consumed = lineEnd + 1;
        caret += next[index].length + 1;
    }

    return { value: rewritten, selection: { start: caret, end: caret } };
}
