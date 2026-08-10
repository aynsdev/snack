import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ContainerCanvasTabs } from '@/components/snack/container-canvas-tabs';
import type { CanvasDocument } from '@/types';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
    }: {
        children: React.ReactNode;
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url}>{children}</a>,
    router: { post },
}));

vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({ slug: 'snack-hq' }),
}));

const canvas: CanvasDocument = {
    id: '01K2CANVASDOCUMENT00000000',
    title: 'Launch notes',
    excerpt: '',
    creator: null,
    editor: null,
    created_at: null,
    updated_at: null,
    can_update: true,
    can_delete: true,
};

describe('ContainerCanvasTabs', () => {
    beforeEach(() => vi.clearAllMocks());

    it('shows Canvas tabs and creates one immediately from the plus button', async () => {
        const user = userEvent.setup();

        render(
            <ContainerCanvasTabs
                canvases={[canvas]}
                createUrl="/w/snack-hq/channels/general/canvases"
                canCreate
                menuLabel="Add to this channel"
            />,
        );

        expect(
            screen.getByRole('link', { name: /Launch notes/ }),
        ).toHaveAttribute(
            'href',
            '/w/snack-hq/canvases/01K2CANVASDOCUMENT00000000',
        );

        await user.click(screen.getByRole('button', { name: 'Add a tab' }));
        await user.click(screen.getByRole('menuitem', { name: /Canvas/ }));

        expect(post).toHaveBeenCalledWith(
            '/w/snack-hq/channels/general/canvases',
            {},
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('hides creation for a read-only container', () => {
        render(
            <ContainerCanvasTabs
                canvases={[canvas]}
                createUrl="/unused"
                canCreate={false}
                menuLabel="Add to this conversation"
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Add a tab' }),
        ).not.toBeInTheDocument();
    });
});
