import { describe, expect, it } from 'vitest';
import { parseInline, parseMessage } from '@/lib/message-format';

/**
 * The message parser.
 *
 * Its most important property is negative: nothing it produces is markup. The
 * tests that matter most are the ones proving a hostile body stays inert text
 * no matter how it is dressed up.
 */
describe('parseMessage', () => {
    it('treats a plain message as one paragraph', () => {
        expect(parseMessage('Hello there')).toEqual([
            {
                type: 'paragraph',
                children: [{ type: 'text', value: 'Hello there' }],
            },
        ]);
    });

    it('splits paragraphs on a blank line', () => {
        const blocks = parseMessage('first\n\nsecond');

        expect(blocks).toHaveLength(2);
        expect(blocks[0].type).toBe('paragraph');
        expect(blocks[1].type).toBe('paragraph');
    });

    it('keeps a single newline inside one paragraph', () => {
        // A soft break is the author's line break, not a new thought.
        const blocks = parseMessage('line one\nline two');

        expect(blocks).toHaveLength(1);
        expect(blocks[0]).toMatchObject({
            children: [{ type: 'text', value: 'line one\nline two' }],
        });
    });

    it('parses a fenced code block with its language', () => {
        expect(parseMessage('```php\n$user->save();\n```')).toEqual([
            { type: 'codeblock', language: 'php', value: '$user->save();' },
        ]);
    });

    it('parses a fenced code block without a language', () => {
        expect(parseMessage('```\nplain\n```')).toEqual([
            { type: 'codeblock', language: null, value: 'plain' },
        ]);
    });

    it('runs an unterminated fence to the end of the message', () => {
        // The author clearly meant "code from here"; reinterpreting the rest as
        // markup would be the wrong guess.
        expect(parseMessage('```\nnever closed')).toEqual([
            { type: 'codeblock', language: null, value: 'never closed' },
        ]);
    });

    it('does not format anything inside a code block', () => {
        const blocks = parseMessage('```\n**not bold** <script>\n```');

        expect(blocks[0]).toEqual({
            type: 'codeblock',
            language: null,
            value: '**not bold** <script>',
        });
    });

    it('parses a blockquote and merges its lines', () => {
        expect(parseMessage('> quoted\n> still quoted')).toEqual([
            {
                type: 'quote',
                children: [{ type: 'text', value: 'quoted\nstill quoted' }],
            },
        ]);
    });

    it('leaves a greater-than sign mid-sentence alone', () => {
        const blocks = parseMessage('if a > b then');

        expect(blocks[0].type).toBe('paragraph');
    });
});

describe('parseInline', () => {
    it('parses bold, italic, strike, and code', () => {
        expect(parseInline('**b**')).toEqual([
            { type: 'bold', children: [{ type: 'text', value: 'b' }] },
        ]);
        expect(parseInline('_i_')).toEqual([
            { type: 'italic', children: [{ type: 'text', value: 'i' }] },
        ]);
        expect(parseInline('~~s~~')).toEqual([
            { type: 'strike', children: [{ type: 'text', value: 's' }] },
        ]);
        expect(parseInline('`c`')).toEqual([{ type: 'code', value: 'c' }]);
    });

    it('nests formatting', () => {
        expect(parseInline('**bold with _italic_**')).toEqual([
            {
                type: 'bold',
                children: [
                    { type: 'text', value: 'bold with ' },
                    {
                        type: 'italic',
                        children: [{ type: 'text', value: 'italic' }],
                    },
                ],
            },
        ]);
    });

    it('does not format inside inline code', () => {
        // The only way to write about the syntax is for code to win.
        expect(parseInline('`**not bold**`')).toEqual([
            { type: 'code', value: '**not bold**' },
        ]);
    });

    it('keeps surrounding text', () => {
        expect(parseInline('say **this** now')).toEqual([
            { type: 'text', value: 'say ' },
            { type: 'bold', children: [{ type: 'text', value: 'this' }] },
            { type: 'text', value: ' now' },
        ]);
    });

    it('links a bare http url', () => {
        expect(parseInline('see https://snack.test/docs')).toEqual([
            { type: 'text', value: 'see ' },
            {
                type: 'link',
                href: 'https://snack.test/docs',
                value: 'https://snack.test/docs',
            },
        ]);
    });

    it('leaves trailing punctuation out of a link', () => {
        const nodes = parseInline('read https://snack.test.');

        expect(nodes[1]).toMatchObject({ href: 'https://snack.test' });
        expect(nodes[2]).toEqual({ type: 'text', value: '.' });
    });

    it('never produces a link for a javascript url', () => {
        // The pattern only matches http(s), so there is no path by which a
        // script URL can reach an href.
        const nodes = parseInline('javascript:alert(1)');

        expect(nodes.every((node) => node.type !== 'link')).toBe(true);
    });

    it('never produces a link for a data url', () => {
        const nodes = parseInline('data:text/html;base64,PHNjcmlwdD4=');

        expect(nodes.every((node) => node.type !== 'link')).toBe(true);
    });

    it('treats html as ordinary text', () => {
        expect(parseInline('<script>alert(1)</script>')).toEqual([
            { type: 'text', value: '<script>alert(1)</script>' },
        ]);
    });

    it('does not carry regex state between calls', () => {
        // The shared pattern is stateful under /g; reusing it across calls
        // would silently drop matches on every other invocation.
        expect(parseInline('**a**')).toEqual(parseInline('**a**'));
    });
});
