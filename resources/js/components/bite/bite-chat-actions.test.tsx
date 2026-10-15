import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BiteChatActions } from '@/components/bite/bite-chat-actions';
import type { BiteChatSummary } from '@/types/bite';

const router = vi.hoisted(() => ({
    patch: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
}));
const api = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));
const copy = vi.hoisted(() => vi.fn().mockResolvedValue(true));
vi.mock('@inertiajs/react', () => ({
    router,
    Form: ({
        children,
    }: {
        children: (props: {
            processing: boolean;
            errors: Record<string, string>;
        }) => ReactNode;
    }) => <form>{children({ processing: false, errors: {} })}</form>,
}));
vi.mock('@/hooks/use-workspace', () => ({
    useWorkspace: () => ({ slug: 'snack-hq', name: 'Snack HQ' }),
}));
vi.mock('@/hooks/use-clipboard', () => ({ useClipboard: () => [null, copy] }));
vi.mock('@/lib/api', () => ({
    api,
    ApiError: class ApiError extends Error {},
}));

const chat: BiteChatSummary = {
    id: 'chat-one',
    title: 'Launch ideas',
    is_pinned: false,
    is_archived: false,
    share_url: null,
    last_message_at: null,
    created_at: null,
};

beforeEach(() => vi.clearAllMocks());

async function openMenu(overrides: Partial<BiteChatSummary> = {}) {
    const user = userEvent.setup();
    render(<BiteChatActions chat={{ ...chat, ...overrides }} />);
    await user.click(
        screen.getByRole('button', { name: 'Options for Launch ideas' }),
    );

    return user;
}

describe('Bite chat actions', () => {
    it('offers all five actions from a recent-chat menu', async () => {
        await openMenu();

        for (const name of ['Rename', 'Pin', 'Share', 'Archive', 'Delete']) {
            expect(screen.getByRole('menuitem', { name })).toBeInTheDocument();
        }
    });

    it('pins a chat without opening it', async () => {
        const user = await openMenu();
        await user.click(screen.getByRole('menuitem', { name: 'Pin' }));
        expect(router.patch).toHaveBeenCalledWith(
            '/w/snack-hq/ai/chats/chat-one',
            { is_pinned: true },
            expect.any(Object),
        );
    });

    it('offers restoring an archived chat', async () => {
        const user = await openMenu({ is_archived: true });
        await user.click(screen.getByRole('menuitem', { name: 'Restore' }));
        expect(router.patch).toHaveBeenCalledWith(
            '/w/snack-hq/ai/chats/chat-one',
            { is_archived: false },
            expect.any(Object),
        );
    });

    it('opens the rename form with the current title', async () => {
        const user = await openMenu();
        await user.click(screen.getByRole('menuitem', { name: 'Rename' }));
        expect(
            screen.getByRole('dialog', { name: 'Rename chat' }),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Title')).toHaveValue('Launch ideas');
    });

    it('creates and revokes a read-only workspace link only after explicit actions', async () => {
        api.post.mockResolvedValue({
            url: 'https://snack.test/w/snack-hq/ai/shared/token',
        });
        api.delete.mockResolvedValue({ revoked: true });
        const user = await openMenu();
        await user.click(screen.getByRole('menuitem', { name: 'Share' }));
        expect(screen.getByText(/Anyone in Snack HQ/)).toBeInTheDocument();
        expect(api.post).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Create link' }));
        expect(api.post).toHaveBeenCalledWith(
            '/w/snack-hq/ai/chats/chat-one/share',
        );
        expect(screen.getByLabelText('Read-only link')).toHaveValue(
            'https://snack.test/w/snack-hq/ai/shared/token',
        );
        await user.click(screen.getByRole('button', { name: 'Copy' }));
        expect(
            screen.getByRole('button', { name: 'Copied' }),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Stop sharing' }));
        expect(api.delete).toHaveBeenCalledWith(
            '/w/snack-hq/ai/chats/chat-one/share',
        );
        expect(
            screen.queryByLabelText('Read-only link'),
        ).not.toBeInTheDocument();
    });

    it('requires confirming deletion and allows cancelling', async () => {
        const user = await openMenu();
        await user.click(screen.getByRole('menuitem', { name: 'Delete' }));
        expect(
            screen.getByRole('alertdialog', { name: 'Delete chat?' }),
        ).toBeInTheDocument();
        expect(router.delete).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(router.delete).not.toHaveBeenCalled();
    });
});
