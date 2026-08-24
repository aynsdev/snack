import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import FilesIndex from '@/pages/files/index';
import type { FileRow } from '@/types';

const { get } = vi.hoisted(() => ({
    get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { get },
}));

vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({ slug: 'snack-hq', name: 'Snack HQ' }),
    useSidebarConversations: () => ({ conversations: [] }),
}));

function makeFile(overrides: Partial<FileRow> = {}): FileRow {
    return {
        id: 1,
        name: 'budget.pdf',
        mime_type: 'application/pdf',
        size: 2048,
        human_size: '2 KB',
        kind: 'pdf',
        width: null,
        height: null,
        is_animated: false,
        download_url: '/files/1?signature=abc',
        thumbnail_url: null,
        preview_url: null,
        created_at: '2026-07-31T09:00:00+00:00',
        uploader: {
            id: 1,
            name: 'Alex',
            username: 'alex',
            avatar_path: null,
            title: null,
            presence_status: 'online',
        },
        container: {
            kind: 'channel',
            id: 1,
            name: 'Design',
            slug: 'design',
            type: 'public',
        },
        message_id: 42,
        ...overrides,
    };
}

const defaultProps = {
    files: [makeFile()],
    filters: { kind: null, channel: null, q: null },
    channels: [{ id: 1, name: 'Design', slug: 'design' }],
    next_cursor: null,
};

describe('FilesIndex', () => {
    beforeEach(() => vi.clearAllMocks());

    it('opens a preview dialog instead of downloading when a row is clicked', async () => {
        const user = userEvent.setup();

        render(<FilesIndex {...defaultProps} />);

        expect(
            screen.queryByRole('link', { name: /budget\.pdf/ }),
        ).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: /budget\.pdf/ }),
        );

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /download/i }),
        ).toHaveAttribute('href', '/files/1?signature=abc');
    });

    it('previews a pdf in an iframe', async () => {
        const user = userEvent.setup();

        render(
            <FilesIndex
                {...defaultProps}
                files={[
                    makeFile({
                        preview_url: '/files/1?inline=1&signature=abc',
                    }),
                ]}
            />,
        );

        await user.click(screen.getByRole('button', { name: /budget\.pdf/ }));

        expect(
            screen.getByTitle('budget.pdf'),
        ).toHaveAttribute('src', '/files/1?inline=1&signature=abc');
    });

    it('previews an image in the dialog', async () => {
        const user = userEvent.setup();

        render(
            <FilesIndex
                {...defaultProps}
                files={[
                    makeFile({
                        name: 'shot.png',
                        kind: 'image',
                        mime_type: 'image/png',
                        preview_url: '/files/1?inline=1&signature=abc',
                        thumbnail_url: '/files/1/thumbnail?signature=abc',
                    }),
                ]}
            />,
        );

        await user.click(screen.getByRole('button', { name: /shot\.png/ }));

        expect(screen.getByRole('img', { name: 'shot.png' })).toHaveAttribute(
            'src',
            '/files/1/thumbnail?signature=abc',
        );
    });
});
