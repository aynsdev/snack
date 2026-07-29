/**
 * Snack's message syntax.
 *
 * # Why a parser and not a markdown library
 *
 * Message bodies are stored as the author's source text (see
 * App\Services\MessageBody). This turns that source into a tree of typed nodes,
 * which the renderer maps to React elements — so a message becomes elements and
 * text nodes, never an HTML string. There is no `dangerouslySetInnerHTML`
 * anywhere in the path, which is what makes a hostile body structurally inert
 * rather than merely sanitized: `<script>alert(1)</script>` is a text node
 * reading exactly that.
 *
 * A general markdown library would drag in raw-HTML passthrough, reference
 * links, and image embedding — features nobody wants in a chat message and each
 * of which is a way back into markup.
 *
 * The grammar is deliberately small:
 *
 *   `code`      **bold**      _italic_      ~~strike~~
 *   > quote     ```fenced code```           bare http(s) links
 */

export type InlineNode =
    | { type: 'text'; value: string }
    | { type: 'code'; value: string }
    | { type: 'link'; href: string; value: string }
    | { type: 'bold'; children: InlineNode[] }
    | { type: 'italic'; children: InlineNode[] }
    | { type: 'strike'; children: InlineNode[] };

export type BlockNode =
    | { type: 'paragraph'; children: InlineNode[] }
    | { type: 'quote'; children: InlineNode[] }
    | { type: 'codeblock'; language: string | null; value: string };

/**
 * Inline delimiters, in precedence order.
 *
 * Code comes first and deliberately consumes its contents whole: inside
 * backticks nothing else is markup, which is the only way to write about the
 * syntax itself.
 */
const INLINE_PATTERN = new RegExp(
    [
        '(`[^`\\n]+`)', // `code`
        '(\\*\\*[\\s\\S]+?\\*\\*)', // **bold**
        '(~~[\\s\\S]+?~~)', // ~~strike~~
        '(_[^_\\n]+_)', // _italic_
        '(https?://[^\\s<>()]+[^\\s<>().,;:!?])', // bare link
    ].join('|'),
    'g',
);

const FENCE = /^```([a-z0-9+#-]*)\s*$/i;

/**
 * Parse a message body into blocks.
 */
export function parseMessage(body: string): BlockNode[] {
    const lines = body.split('\n');
    const blocks: BlockNode[] = [];

    let paragraph: string[] = [];

    const flushParagraph = () => {
        if (paragraph.length > 0) {
            blocks.push({
                type: 'paragraph',
                children: parseInline(paragraph.join('\n')),
            });
            paragraph = [];
        }
    };

    for (let index = 0; index < lines.length; index++) {
        const line = lines[index];
        const fence = line.match(FENCE);

        if (fence) {
            flushParagraph();

            const code: string[] = [];
            index++;

            // An unterminated fence runs to the end of the message rather than
            // falling back to plain text — the author clearly meant "code from
            // here", and reinterpreting it as markup would be the wrong guess.
            while (index < lines.length && !FENCE.test(lines[index])) {
                code.push(lines[index]);
                index++;
            }

            blocks.push({
                type: 'codeblock',
                language: fence[1] || null,
                value: code.join('\n'),
            });

            continue;
        }

        if (line.startsWith('> ') || line === '>') {
            flushParagraph();

            const quoted: string[] = [];

            while (
                index < lines.length &&
                (lines[index].startsWith('> ') || lines[index] === '>')
            ) {
                quoted.push(lines[index].replace(/^>\s?/, ''));
                index++;
            }

            index--;

            blocks.push({
                type: 'quote',
                children: parseInline(quoted.join('\n')),
            });

            continue;
        }

        if (line.trim() === '') {
            flushParagraph();

            continue;
        }

        paragraph.push(line);
    }

    flushParagraph();

    return blocks;
}

/**
 * Parse the inline formatting inside a run of text.
 */
export function parseInline(text: string): InlineNode[] {
    const nodes: InlineNode[] = [];
    let lastIndex = 0;

    // A fresh regex each call: the shared one is stateful under the /g flag,
    // and reusing it across calls would drop matches unpredictably.
    const pattern = new RegExp(INLINE_PATTERN.source, 'g');
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > lastIndex) {
            nodes.push({
                type: 'text',
                value: text.slice(lastIndex, match.index),
            });
        }

        const [token, code, bold, strike, italic, link] = match;

        if (code !== undefined) {
            nodes.push({ type: 'code', value: code.slice(1, -1) });
        } else if (bold !== undefined) {
            nodes.push({
                type: 'bold',
                children: parseInline(bold.slice(2, -2)),
            });
        } else if (strike !== undefined) {
            nodes.push({
                type: 'strike',
                children: parseInline(strike.slice(2, -2)),
            });
        } else if (italic !== undefined) {
            nodes.push({
                type: 'italic',
                children: parseInline(italic.slice(1, -1)),
            });
        } else if (link !== undefined) {
            /*
             * The pattern only ever matches http(s), so a `javascript:` or
             * `data:` URL cannot reach an href through this path. Anything else
             * the author types stays plain text.
             */
            nodes.push({ type: 'link', href: link, value: link });
        }

        lastIndex = match.index + token.length;
    }

    if (lastIndex < text.length) {
        nodes.push({ type: 'text', value: text.slice(lastIndex) });
    }

    return nodes;
}

/**
 * Whether a body is worth rendering at all.
 *
 * The server rejects empty messages, so this only guards against a body that
 * became empty in transit.
 */
export function isBlank(body: string): boolean {
    return body.trim() === '';
}
