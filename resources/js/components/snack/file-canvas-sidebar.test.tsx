import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FileCanvasSidebar } from '@/components/snack/file-canvas-sidebar';
import type { FileRow } from '@/types';

const { post, remove } = vi.hoisted(() => ({
    post: vi.fn(),
    remove: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { post, delete: remove },
    usePage: () => ({
        props: {
            file_canvas_sidebar: {
                recent: [
                    {
                        id: 'file:4',
                        target_id: 4,
                        type: 'file',
                        label: 'launch-brief.pdf',
                        is_starred: false,
                        file: makeFile(),
                    },
                    {
                        id: 'canvas:01K2CANVAS',
                        target_id: '01K2CANVAS',
                        type: 'canvas',
                        label: 'Launch notes',
                        url: '/w/snack-hq/canvases/01K2CANVAS',
                        is_starred: true,
                    },
                ],
                starred: [],
            },
        },
    }),
}));

vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({
        slug: 'snack-hq',
        abilities: { is_guest: false },
    }),
    useSidebarConversations: () => ({ conversations: [] }),
}));

vi.mock('@/hooks/use-active-channel', () => ({
    useIsFilesView: () => false,
    useIsCanvasesView: () => true,
}));

function makeFile(overrides: Partial<FileRow> = {}): FileRow {
    return {
        id: 4,
        name: 'launch-brief.pdf',
        mime_type: 'application/pdf',
        size: 2048,
        human_size: '2 KB',
        kind: 'pdf',
        width: null,
        height: null,
        is_animated: false,
        download_url: '/files/4?signature=signed',
        preview_url: '/files/4?inline=1&signature=signed',
        thumbnail_url: null,
        created_at: '2026-07-31T09:00:00+00:00',
        uploader: null,
        container: {
            kind: 'channel',
            id: 1,
            name: 'Launch',
            slug: 'launch',
            type: 'public',
        },
        message_id: 42,
        ...overrides,
    };
}

describe('FileCanvasSidebar', () => {
    beforeEach(() => vi.clearAllMocks());

    it('opens a preview dialog instead of downloading a recent file', async () => {
        const user = userEvent.setup();

        render(<FileCanvasSidebar />);

        expect(
            screen.queryByRole('link', { name: 'launch-brief.pdf' }),
        ).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'launch-brief.pdf' }),
        );

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(
            screen.getByTitle('launch-brief.pdf'),
        ).toHaveAttribute('src', '/files/4?inline=1&signature=signed');
    });
});
