import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CanvasShow from '@/pages/canvases/show';
import type { CanvasDocument } from '@/types';

const { patch, post, deleteVisit } = vi.hoisted(() => ({
    patch: vi.fn(),
    post: vi.fn(),
    deleteVisit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        children,
        href,
    }: {
        children: React.ReactNode;
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url}>{children}</a>,
    router: {
        patch,
        post,
        delete: deleteVisit,
    },
}));

vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({ slug: 'snack-hq', name: 'Snack HQ' }),
}));

vi.mock('@/components/snack/canvas-rich-text-editor', () => ({
    CanvasRichTextEditor: ({
        editable,
        onChange,
    }: {
        editable: boolean;
        onChange?: (content: object, text: string) => void;
    }) => (
        <div aria-label="Canvas content" data-editable={editable}>
            {editable && (
                <button
                    type="button"
                    onClick={() =>
                        onChange?.(
                            {
                                type: 'doc',
                                content: [
                                    {
                                        type: 'paragraph',
                                        content: [
                                            {
                                                type: 'text',
                                                text: 'Updated plan',
                                                marks: [{ type: 'bold' }],
                                            },
                                        ],
                                    },
                                ],
                            },
                            'Updated plan',
                        )
                    }
                >
                    Change document
                </button>
            )}
        </div>
    ),
}));

const initialContent = {
    type: 'doc',
    content: [
        {
            type: 'paragraph',
            content: [{ type: 'text', text: 'Draft plan' }],
        },
    ],
};

const canvas: CanvasDocument = {
    id: '01K2CANVASDOCUMENT00000000',
    title: 'Launch plan',
    body: 'Draft plan',
    content: initialContent,
    cover_url: null,
    excerpt: 'Draft plan',
    creator: null,
    editor: null,
    created_at: '2026-08-10T00:00:00Z',
    updated_at: '2026-08-10T00:00:00Z',
    can_update: true,
    can_delete: true,
    is_starred: false,
};

describe('CanvasShow', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('saves a structured Tiptap document', async () => {
        const user = userEvent.setup();
        render(<CanvasShow canvas={canvas} />);

        await user.click(
            screen.getByRole('button', { name: 'Change document' }),
        );

        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(patch).toHaveBeenCalledWith(
            '/w/snack-hq/canvases/01K2CANVASDOCUMENT00000000',
            expect.objectContaining({
                title: 'Launch plan',
                content: expect.objectContaining({ type: 'doc' }),
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('uploads a cover image', async () => {
        const user = userEvent.setup();
        render(<CanvasShow canvas={canvas} />);

        const upload = screen.getByLabelText('Choose a Canvas cover image');
        const cover = new File(['image'], 'cover.png', { type: 'image/png' });

        await user.upload(upload, cover);

        expect(post).toHaveBeenCalledWith(
            '/w/snack-hq/canvases/01K2CANVASDOCUMENT00000000/cover',
            { cover },
            expect.objectContaining({ forceFormData: true }),
        );
    });

    it('keeps a read-only canvas non-editable', () => {
        render(
            <CanvasShow
                canvas={{ ...canvas, can_update: false, can_delete: false }}
            />,
        );

        expect(screen.getByLabelText('Canvas title')).toHaveAttribute(
            'readonly',
        );
        expect(screen.getByLabelText('Canvas content')).toHaveAttribute(
            'data-editable',
            'false',
        );
        expect(
            screen.queryByRole('button', { name: 'Save' }),
        ).not.toBeInTheDocument();
    });
});
