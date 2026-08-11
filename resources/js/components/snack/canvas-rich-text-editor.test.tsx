import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { CanvasRichTextEditor } from '@/components/snack/canvas-rich-text-editor';

describe('CanvasRichTextEditor', () => {
    it('inserts an emoji at the current Canvas selection', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(
            <CanvasRichTextEditor
                editable
                content={{ type: 'doc', content: [{ type: 'paragraph' }] }}
                onChange={onChange}
            />,
        );

        const editor = await screen.findByLabelText('Canvas content');
        await user.click(editor);
        await user.click(screen.getByRole('button', { name: 'Insert emoji' }));
        await user.click(screen.getByRole('menuitem', { name: 'Insert 🚀' }));

        await waitFor(() => {
            expect(editor).toHaveTextContent('🚀');
            expect(onChange).toHaveBeenLastCalledWith(expect.any(Object), '🚀');
        });
    });

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
