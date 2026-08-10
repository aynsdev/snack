import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CanvasRichTextEditor } from '@/components/snack/canvas-rich-text-editor';

describe('CanvasRichTextEditor', () => {
    it('renders rich document blocks from structured Tiptap JSON', async () => {
        render(
            <CanvasRichTextEditor
                editable={false}
                content={{
                    type: 'doc',
                    content: [
                        {
                            type: 'heading',
                            attrs: { level: 2 },
                            content: [{ type: 'text', text: 'Launch plan' }],
                        },
                        {
                            type: 'taskList',
                            content: [
                                {
                                    type: 'taskItem',
                                    attrs: { checked: true },
                                    content: [
                                        {
                                            type: 'paragraph',
                                            content: [
                                                {
                                                    type: 'text',
                                                    text: 'Confirm scope',
                                                },
                                            ],
                                        },
                                    ],
                                },
                            ],
                        },
                        {
                            type: 'table',
                            content: [
                                {
                                    type: 'tableRow',
                                    content: [
                                        {
                                            type: 'tableHeader',
                                            content: [
                                                {
                                                    type: 'paragraph',
                                                    content: [
                                                        {
                                                            type: 'text',
                                                            text: 'Owner',
                                                        },
                                                    ],
                                                },
                                            ],
                                        },
                                    ],
                                },
                            ],
                        },
                    ],
                }}
            />,
        );

        await screen.findByText('Launch plan');

        expect(
            screen.getByRole('heading', { name: 'Launch plan' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Confirm scope')).toBeInTheDocument();
        expect(screen.getByRole('table')).toHaveTextContent('Owner');
        expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
    });
});
