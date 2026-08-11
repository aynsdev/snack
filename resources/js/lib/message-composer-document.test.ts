import { describe, expect, it } from 'vitest';
import { serializeMessageDocument } from '@/lib/message-composer-document';

describe('serializeMessageDocument', () => {
    it('preserves Snack inline formatting and hard breaks', () => {
        expect(
            serializeMessageDocument({
                type: 'doc',
                content: [
                    {
                        type: 'paragraph',
                        content: [
                            {
                                type: 'text',
                                text: 'Ship',
                                marks: [{ type: 'bold' }],
                            },
                            { type: 'text', text: ' it' },
                            { type: 'hardBreak' },
                            {
                                type: 'text',
                                text: 'today',
                                marks: [{ type: 'italic' }],
                            },
                        ],
                    },
                ],
            }),
        ).toBe('**Ship** it\n_today_');
    });

    it('serializes lists, quotes, and fenced code for the existing renderer', () => {
        expect(
            serializeMessageDocument({
                type: 'doc',
                content: [
                    {
                        type: 'bulletList',
                        content: [
                            {
                                type: 'listItem',
                                content: [
                                    {
                                        type: 'paragraph',
                                        content: [
                                            { type: 'text', text: 'First' },
                                        ],
                                    },
                                ],
                            },
                            {
                                type: 'listItem',
                                content: [
                                    {
                                        type: 'paragraph',
                                        content: [
                                            { type: 'text', text: 'Second' },
                                        ],
                                    },
                                ],
                            },
                        ],
                    },
                    {
                        type: 'blockquote',
                        content: [
                            {
                                type: 'paragraph',
                                content: [{ type: 'text', text: 'Approved' }],
                            },
                        ],
                    },
                    {
                        type: 'codeBlock',
                        attrs: { language: 'ts' },
                        content: [
                            { type: 'text', text: 'const ready = true;' },
                        ],
                    },
                ],
            }),
        ).toBe(
            '- First\n- Second\n\n> Approved\n\n```ts\nconst ready = true;\n```',
        );
    });
});
