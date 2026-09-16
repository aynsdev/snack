import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkspaceLayout from '@/layouts/workspace-layout';

const useCurrentWorkspace = vi.hoisted(() => vi.fn());
const useCurrentUser = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => undefined) },
    usePage: () => ({ props: { flash: { entered_workspace: false } } }),
}));

vi.mock('@/components/snack/channel-sync', () => ({
    ChannelSync: () => null,
}));
vi.mock('@/components/snack/conversation-sync', () => ({
    ConversationSync: () => null,
}));
vi.mock('@/components/snack/huddle/huddle-tray', () => ({
    HuddleTray: () => null,
}));
vi.mock('@/components/snack/huddle-sync', () => ({ HuddleSync: () => null }));
vi.mock('@/components/snack/nav-rail', () => ({ NavRail: () => null }));
vi.mock('@/components/snack/workspace-rail', () => ({
    WorkspaceRail: () => null,
}));
vi.mock('@/components/snack/workspace-sidebar', () => ({
    WorkspaceSidebar: () => null,
}));
vi.mock('@/hooks/use-active-channel', () => ({
    useIsActivityView: () => false,
}));
vi.mock('@/hooks/use-huddle', () => ({
    HuddleProvider: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/hooks/use-live-presence', () => ({
    LivePresenceProvider: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/hooks/use-presence', () => ({ usePresence: vi.fn() }));
vi.mock('@/hooks/use-workspace', () => ({
    useCurrentUser,
    useCurrentWorkspace,
}));

beforeEach(() => {
    useCurrentWorkspace.mockReturnValue({
        workspace: { id: 1, name: 'Snack HQ', slug: 'snack-hq' },
    });
    useCurrentUser.mockReturnValue(null);
});

describe('WorkspaceLayout', () => {
    it('contains overscroll inside the fixed-height workspace shell', () => {
        const { container } = render(
            <WorkspaceLayout>
                <div>Channel content</div>
            </WorkspaceLayout>,
        );

        expect(container.firstElementChild).toHaveClass(
            'h-dvh',
            'overflow-hidden',
            'overscroll-none',
        );
    });
});
