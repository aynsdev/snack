import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CanvasIndex from '@/pages/canvases/index';
import type { CanvasDocument } from '@/types';

const { get, post, remove } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    remove: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { get, post, delete: remove },
}));

vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({ slug: 'snack-hq', name: 'Snack HQ' }),
}));

const canvas: CanvasDocument = {
    id: '01CANVAS',
    title: 'Launch notes',
    excerpt: 'Milestones for the launch.',
    creator: null,
    editor: null,
    created_at: '2026-08-10T08:00:00Z',
    updated_at: '2026-08-11T08:00:00Z',
    can_update: true,
    can_delete: true,
    is_starred: false,
    container: {
        type: 'channel',
        label: '#launch',
        url: '/w/snack-hq/channels/launch',
    },
};

const defaultProps = {
    filters: { q: null, scope: 'all' as const, sort: 'updated' as const },
    pagination: { next: null, previous: null },
    abilities: { create: true },
};

describe('CanvasIndex', () => {
    beforeEach(() => vi.clearAllMocks());

    it('creates and opens an untitled Canvas without a checkpoint dialog', async () => {
        const user = userEvent.setup();

        render(<CanvasIndex canvases={[]} {...defaultProps} />);

        await user.click(
            screen.getAllByRole('button', { name: 'New canvas' })[0],
        );

        expect(post).toHaveBeenCalledWith(
            '/w/snack-hq/canvases',
            {},
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('creates a prefilled Canvas from a template', async () => {
        const user = userEvent.setup();

        render(<CanvasIndex canvases={[canvas]} {...defaultProps} />);

        await user.click(
            screen.getByRole('button', { name: /Employee onboarding/ }),
        );

        expect(post).toHaveBeenCalledWith(
            '/w/snack-hq/canvases',
            { template: 'employee-onboarding' },
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });

    it('identifies the location and exposes the Canvas actions', async () => {
        const user = userEvent.setup();

        render(<CanvasIndex canvases={[canvas]} {...defaultProps} />);

        expect(screen.getByText('#launch')).toBeInTheDocument();

        await user.click(
            screen.getByRole('button', {
                name: 'More actions for Launch notes',
            }),
        );

        expect(screen.getByText('Share canvas')).toBeInTheDocument();
        expect(screen.getByText('View canvas details')).toBeInTheDocument();
        expect(screen.getByText('Copy link to canvas')).toBeInTheDocument();
        expect(screen.getByText('Open in new window')).toBeInTheDocument();
        expect(screen.getByText('Delete canvas')).toBeInTheDocument();

        await user.click(screen.getByText('Delete canvas'));

        expect(
            screen.getByRole('alertdialog', { name: 'Delete “Launch notes”?' }),
        ).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Delete canvas' }));

        expect(remove).toHaveBeenCalledWith(
            '/w/snack-hq/canvases/01CANVAS',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('stars a Canvas from its row', async () => {
        const user = userEvent.setup();

        render(<CanvasIndex canvases={[canvas]} {...defaultProps} />);

        await user.click(
            screen.getByRole('button', { name: 'Star Launch notes' }),
        );

        expect(post).toHaveBeenCalledWith(
            '/w/snack-hq/canvases/01CANVAS/star',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('requests server-backed ownership filters', async () => {
        const user = userEvent.setup();

        render(<CanvasIndex canvases={[canvas]} {...defaultProps} />);

        await user.click(
            screen.getByRole('button', { name: 'Created by you' }),
        );

        expect(get).toHaveBeenCalledWith(
            '/w/snack-hq/canvases',
            { scope: 'created', sort: 'updated' },
            { preserveState: true, replace: true },
        );
    });
});
