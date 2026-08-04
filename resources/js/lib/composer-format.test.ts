import { describe, expect, it } from 'vitest';
import {
    applyCommand,
    insertMention,
    insertText,
    toggleCodeBlock,
    toggleInline,
    toggleLinePrefix,
} from '@/lib/composer-format';

/**
 * The toolbar's edits.
 *
 * Two properties matter throughout. Everything the toolbar writes has to parse
 * back to what the button promised — the markers are the same ones
 * `message-format.ts` reads — and the selection has to come back usable, since a
 * button that drops the caret at the end of the message is one nobody presses
 * twice.
 */
describe('toggleInline', () => {
    it('wraps the selection and keeps it selected', () => {
        const edit = toggleInline('make me bold', { start: 8, end: 12 }, '**');

        expect(edit.value).toBe('make me **bold**');
        // Still over "bold", so a second press undoes it.
        expect(edit.value.slice(edit.selection.start, edit.selection.end)).toBe(
            'bold',
        );
    });

    it('leaves the caret between the markers when nothing is selected', () => {
        const edit = toggleInline('', { start: 0, end: 0 }, '**');

        expect(edit.value).toBe('****');
        expect(edit.selection).toEqual({ start: 2, end: 2 });
    });

    it('unwraps when the markers are inside the selection', () => {
        const edit = toggleInline('**bold**', { start: 0, end: 8 }, '**');

        expect(edit.value).toBe('bold');
        expect(edit.selection).toEqual({ start: 0, end: 4 });
    });

    it('unwraps when the markers sit just outside it', () => {
        // What a double-click on the word gives you.
        const edit = toggleInline('a **bold** b', { start: 4, end: 8 }, '**');

        expect(edit.value).toBe('a bold b');
        expect(edit.value.slice(edit.selection.start, edit.selection.end)).toBe(
            'bold',
        );
    });

    it('does not read past the start of the text', () => {
        // The "outside" check must not wrap around to the end of the string.
        expect(toggleInline('x', { start: 0, end: 1 }, '**').value).toBe(
            '**x**',
        );
    });
});

describe('toggleLinePrefix', () => {
    it('numbers each line of the selection', () => {
        const edit = toggleLinePrefix(
            'first\nsecond\nthird',
            { start: 0, end: 18 },
            'ordered',
        );

        expect(edit.value).toBe('1. first\n2. second\n3. third');
    });

    it('marks the whole line the caret is on', () => {
        // Nobody selects from column zero before pressing a list button.
        const edit = toggleLinePrefix('milk', { start: 2, end: 2 }, 'bullet');

        expect(edit.value).toBe('- milk');
        // The caret stayed inside the word rather than jumping to the end.
        expect(edit.selection).toEqual({ start: 4, end: 4 });
    });

    it('removes the marker when every line already carries it', () => {
        const edit = toggleLinePrefix(
            '- one\n- two',
            { start: 0, end: 11 },
            'bullet',
        );

        expect(edit.value).toBe('one\ntwo');
    });

    it('finishes a half-marked selection instead of clearing it', () => {
        const edit = toggleLinePrefix(
            '- one\ntwo',
            { start: 0, end: 9 },
            'bullet',
        );

        expect(edit.value).toBe('- one\n- two');
    });

    it('swaps one kind of marker for another', () => {
        const edit = toggleLinePrefix(
            '- one\n- two',
            { start: 0, end: 11 },
            'ordered',
        );

        expect(edit.value).toBe('1. one\n2. two');
    });

    it('starts a list in an empty composer', () => {
        const edit = toggleLinePrefix('', { start: 0, end: 0 }, 'bullet');

        expect(edit.value).toBe('- ');
        expect(edit.selection).toEqual({ start: 2, end: 2 });
    });

    it('skips blank lines inside a selection', () => {
        const edit = toggleLinePrefix(
            'one\n\ntwo',
            { start: 0, end: 8 },
            'quote',
        );

        expect(edit.value).toBe('> one\n\n> two');
    });

    it('does not renumber around a blank line by counting it', () => {
        const edit = toggleLinePrefix(
            'one\n\ntwo',
            { start: 0, end: 8 },
            'ordered',
        );

        expect(edit.value).toBe('1. one\n\n2. two');
    });
});

describe('toggleCodeBlock', () => {
    it('fences the selected lines and selects the code', () => {
        const edit = toggleCodeBlock('$user->save();', { start: 0, end: 14 });

        expect(edit.value).toBe('```\n$user->save();\n```');
        expect(edit.value.slice(edit.selection.start, edit.selection.end)).toBe(
            '$user->save();',
        );
    });

    it('puts the caret inside the fence when nothing is selected', () => {
        const edit = toggleCodeBlock('', { start: 0, end: 0 });

        expect(edit.value).toBe('```\n\n```');
        expect(edit.selection).toEqual({ start: 4, end: 4 });
    });

    it('unfences a block that is already fenced', () => {
        const edit = toggleCodeBlock('```\ncode\n```', { start: 5, end: 5 });

        expect(edit.value).toBe('code');
    });

    it('leaves the rest of the message alone', () => {
        const edit = toggleCodeBlock('before\ncode\nafter', {
            start: 7,
            end: 11,
        });

        expect(edit.value).toBe('before\n```\ncode\n```\nafter');
    });
});

describe('insertText', () => {
    it('replaces the selection and leaves the caret after it', () => {
        const edit = insertText('a b', { start: 2, end: 3 }, '🎉');

        expect(edit.value).toBe('a 🎉');
        expect(edit.selection).toEqual({ start: 2 + '🎉'.length, end: 4 });
    });
});

describe('insertMention', () => {
    it('opens a handle at the caret', () => {
        expect(insertMention('', { start: 0, end: 0 }).value).toBe('@');
    });

    it('separates the handle from the word before it', () => {
        // "ask@ada" is not a mention to the tokenizer, or to a reader.
        expect(insertMention('ask', { start: 3, end: 3 }).value).toBe('ask @');
    });

    it('adds no second space after one', () => {
        expect(insertMention('ask ', { start: 4, end: 4 }).value).toBe('ask @');
    });
});

describe('applyCommand', () => {
    it('maps every command to the marker the grammar reads', () => {
        const selection = { start: 0, end: 4 };

        expect(applyCommand('word', selection, 'bold').value).toBe('**word**');
        expect(applyCommand('word', selection, 'italic').value).toBe('_word_');
        expect(applyCommand('word', selection, 'strike').value).toBe(
            '~~word~~',
        );
        expect(applyCommand('word', selection, 'code').value).toBe('`word`');
        expect(applyCommand('word', selection, 'quote').value).toBe('> word');
        expect(applyCommand('word', selection, 'bullet').value).toBe('- word');
        expect(applyCommand('word', selection, 'ordered').value).toBe(
            '1. word',
        );
        expect(applyCommand('word', selection, 'codeblock').value).toBe(
            '```\nword\n```',
        );
    });
});
