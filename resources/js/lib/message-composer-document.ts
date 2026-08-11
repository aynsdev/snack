import type { JSONContent } from '@tiptap/core';

/**
 * Convert the composer's Tiptap document into Snack's existing safe message
 * source. The server and message renderer continue to receive the same compact
 * grammar they already understand; Tiptap is the authoring surface, not a new
 * storage format.
 */
export function serializeMessageDocument(document: JSONContent): string {
    return serializeBlocks(document.content ?? []).join('\n\n');
}

function serializeBlocks(nodes: JSONContent[]): string[] {
    return nodes.flatMap((node) => {
        switch (node.type) {
            case 'paragraph':
                return [serializeInline(node.content ?? [])];

            case 'blockquote':
                return serializeBlocks(node.content ?? [])
                    .join('\n')
                    .split('\n')
                    .map((line) => `> ${line}`)
                    .join('\n');

            case 'bulletList':
                return serializeList(node, false);

            case 'orderedList':
                return serializeList(node, true);

            case 'codeBlock': {
                const language =
                    typeof node.attrs?.language === 'string'
                        ? node.attrs.language
                        : '';

                return `\`\`\`${language}\n${plainText(node)}\n\`\`\``;
            }

            default: {
                const text = serializeInline(node.content ?? []);

                return text === '' ? [] : [text];
            }
        }
    });
}

function serializeList(node: JSONContent, ordered: boolean): string {
    let item = 0;

    return (node.content ?? [])
        .flatMap((listItem) => {
            if (listItem.type !== 'listItem') {
                return [];
            }

            item++;

            const ownBlocks = (listItem.content ?? []).filter(
                (child) =>
                    child.type !== 'bulletList' && child.type !== 'orderedList',
            );
            const ownText = serializeBlocks(ownBlocks).join('\n');
            const marker = ordered ? `${item}. ` : '- ';
            const lines = ownText.split('\n');
            const current = lines
                .map((line, index) => (index === 0 ? marker : '  ') + line)
                .join('\n');
            const nested = (listItem.content ?? [])
                .filter(
                    (child) =>
                        child.type === 'bulletList' ||
                        child.type === 'orderedList',
                )
                .map((child) =>
                    serializeList(child, child.type === 'orderedList'),
                );

            return [current, ...nested];
        })
        .join('\n');
}

function serializeInline(nodes: JSONContent[]): string {
    return nodes
        .map((node) => {
            if (node.type === 'hardBreak') {
                return '\n';
            }

            if (node.type !== 'text') {
                return serializeInline(node.content ?? []);
            }

            let value = node.text ?? '';

            for (const mark of node.marks ?? []) {
                switch (mark.type) {
                    case 'bold':
                        value = `**${value}**`;
                        break;
                    case 'italic':
                        value = `_${value}_`;
                        break;
                    case 'strike':
                        value = `~~${value}~~`;
                        break;
                    case 'code':
                        value = `\`${value}\``;
                        break;
                    case 'link': {
                        const href = mark.attrs?.href;

                        if (typeof href === 'string' && href !== value) {
                            value = `${value} (${href})`;
                        }

                        break;
                    }
                }
            }

            return value;
        })
        .join('');
}

function plainText(node: JSONContent): string {
    if (node.type === 'hardBreak') {
        return '\n';
    }

    if (node.type === 'text') {
        return node.text ?? '';
    }

    return (node.content ?? []).map(plainText).join('');
}
