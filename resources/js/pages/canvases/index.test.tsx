import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CanvasIndex from '@/pages/canvases/index';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: {
        get: vi.fn(),
        post,
    },
}));

vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({ slug: 'snack-hq', name: 'Snack HQ' }),
}));

describe('CanvasIndex', () => {
    beforeEach(() => vi.clearAllMocks());

    it('creates and opens an untitled Canvas without a checkpoint dialog', async () => {
        const user = userEvent.setup();

        render(
            <CanvasIndex
                canvases={[]}
                filters={{ q: null }}
                pagination={{ next: null, previous: null }}
                abilities={{ create: true }}
            />,
        );

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
});
