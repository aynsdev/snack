import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NavRail } from '@/components/snack/nav-rail';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { UnreadState } from '@/types';

const usePage = vi.hoisted(() => vi.fn());
const currentUrl = vi.hoisted(() => ({
    value: '/w/snack-hq/channels/general',
}));

vi.mock('@inertiajs/react', () => ({
    usePage,
    Link: ({
        children,
        href,
        onClick,
        ...props
    }: {
        children: React.ReactNode;
        href: string | { url: string };
        onClick?: (event: React.MouseEvent<HTMLElement>) => void;
    }) => (
        <a
            href={typeof href === 'string' ? href : href.url}
            // The real Link intercepts the click and visits over XHR; without
            // this jsdom tries to navigate the document instead.
            onClick={(event) => {
                event.preventDefault();
                onClick?.(event);
            }}
            {...props}
        >
            {children}
        </a>
    ),
}));

// The rail reads the URL to decide what is current; the real hook subscribes to
// the Inertia router, which needs a running app.
vi.mock('@/hooks/use-current-url', () => ({
    useCurrentUrl: () => ({ currentUrl: currentUrl.value }),
}));

function mockWorkspace(unread: Partial<UnreadState> = {}) {
    usePage.mockReturnValue({
        props: {
            snack: {
                workspaces: [],
                current: {
                    workspace: {
                        id: 1,
                        name: 'Snack HQ',
                        slug: 'snack-hq',
                        initials: 'SH',
                        logo_url: null,
                        description: null,
                        member_limit: 500,
                        created_at: null,
                        role: 'member',
                        abilities: {
                            administer: false,
                            manage_members: false,
                            create_channels: true,
                            create_projects: true,
                            start_conversations: true,
                            mention_everyone: false,
                            view_audit_logs: false,
                            is_guest: false,
                        },
                    },
                    channels: [],
                    conversations: [],
                    people: [],
                    projects: [],
                    unread: {
                        channels: {},
                        conversations: {},
                        mentions: 0,
                        threads: 0,
                        notifications: 0,
                        ...unread,
                    },
                },
            },
            auth: { user: null, profile: null },
        },
    });
}

/**
 * The app mounts one TooltipProvider at its root (see app.tsx); Radix's tooltip
 * throws without it, so the rail is rendered under one here too.
 */
function renderRail() {
    return render(
        <TooltipProvider>
            <NavRail />
        </TooltipProvider>,
    );
}

beforeEach(() => {
    usePage.mockReset();
    // The rail's section outlives its components on purpose — see
    // use-sidebar-section.ts — so it has to be cleared between tests.
    sessionStorage.clear();
    currentUrl.value = '/w/snack-hq/channels/general';
});

describe('NavRail', () => {
    it('points every destination at the workspace it is rendered in', () => {
        mockWorkspace();

        renderRail();

        expect(screen.getByRole('link', { name: /Home/ })).toHaveAttribute(
            'href',
            '/w/snack-hq',
        );
        expect(screen.getByRole('link', { name: /DMs/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/dms',
        );
        expect(screen.getByRole('link', { name: /Activity/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/activity',
        );
        expect(screen.getByRole('link', { name: /Saved/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/saved',
        );
        expect(screen.getByRole('link', { name: /Files/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/files',
        );
        expect(screen.getByRole('link', { name: /Projects/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/projects',
        );
    });

    it('announces what is coming without offering a link to it', () => {
        mockWorkspace();

        renderRail();

        // A labelled gap answers "where do we keep the logo?" honestly. A link
        // would answer it with a broken page.
        expect(screen.queryByRole('link', { name: 'Assets' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Assets' })).toHaveAttribute(
            'aria-disabled',
            'true',
        );
    });

    it('marks a board as Projects', () => {
        currentUrl.value = '/w/snack-hq/projects/pwa-board';
        mockWorkspace();

        renderRail();

        // The rail names a section, and every project view — board, backlog,
        // settings — is inside it.
        expect(screen.getByRole('link', { name: /Projects/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByRole('link', { name: /Home/ })).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('marks a channel page as Home', () => {
        mockWorkspace();

        renderRail();

        // The rail names a section, and channels are what Home contains.
        expect(screen.getByRole('link', { name: /Home/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('stays on Home for a DM opened from the channel sidebar', () => {
        mockWorkspace();

        const { unmount } = renderRail();

        unmount();

        // The roster sits under the channels, so the sidebar does not change
        // shape on this click — and the rail moving its pill to DMs would point
        // at a column that is not there.
        currentUrl.value = '/w/snack-hq/dms/01HZXCONVERSATION';

        renderRail();

        expect(screen.getByRole('link', { name: /Home/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByRole('link', { name: /DMs/ })).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('stays on DMs for a conversation opened from the DM list', () => {
        currentUrl.value = '/w/snack-hq/dms';
        mockWorkspace();

        const { unmount } = renderRail();

        unmount();

        currentUrl.value = '/w/snack-hq/dms/01HZXCONVERSATION';

        renderRail();

        expect(screen.getByRole('link', { name: /DMs/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByRole('link', { name: /Home/ })).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('claims the section when its own link is redirected into a DM', () => {
        mockWorkspace();

        const { unmount } = renderRail();

        // `/dms` redirects into the newest conversation, so this click lands on
        // the same URL the channel sidebar's roster lands on. The rail has to
        // say where it was going for the section to be reachable at all.
        fireEvent.click(screen.getByRole('link', { name: /DMs/ }));

        unmount();

        currentUrl.value = '/w/snack-hq/dms/01HZXCONVERSATION';

        renderRail();

        expect(screen.getByRole('link', { name: /DMs/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('leaves this tab alone when its DM link is opened in another', () => {
        mockWorkspace();

        const { unmount } = renderRail();

        // A ⌘-click hands the navigation to the browser and leaves this tab on
        // the channel it was reading, so the section must not move either.
        fireEvent.click(screen.getByRole('link', { name: /DMs/ }), {
            metaKey: true,
        });

        unmount();

        currentUrl.value = '/w/snack-hq/dms/01HZXCONVERSATION';

        renderRail();

        expect(screen.getByRole('link', { name: /Home/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('keeps Activity current on the pages it gathers', () => {
        currentUrl.value = '/w/snack-hq/threads';
        mockWorkspace();

        renderRail();

        // Threads is still its own page, reached from the sidebar. Arriving
        // there must not make the section it belongs to go dark.
        expect(screen.getByRole('link', { name: /Activity/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('badges DMs with every unread conversation at once', () => {
        mockWorkspace({
            conversations: {
                conv_a: { messages: 2, mentions: 2 },
                conv_b: { messages: 3, mentions: 3 },
            },
        });

        renderRail();

        const dms = screen.getByRole('link', { name: /DMs/ });

        expect(within(dms).getByText('5')).toBeInTheDocument();
    });

    it('badges Activity with the notification count alone', () => {
        // Not a sum with mentions: a mention writes a notification too, and a
        // badge that counts one event twice disagrees with the page it opens.
        mockWorkspace({ notifications: 4, mentions: 3 });

        renderRail();

        const activity = screen.getByRole('link', { name: /Activity/ });

        expect(within(activity).getByText('4')).toBeInTheDocument();
    });
});
