import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NavRail } from '@/components/snack/nav-rail';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { UnreadState } from '@/types';

const usePage = vi.hoisted(() => vi.fn());
const currentUrl = vi.hoisted(() => ({ value: '/w/snack-hq/channels/general' }));

vi.mock('@inertiajs/react', () => ({
    usePage,
    Link: ({
        children,
        href,
        ...props
    }: {
        children: React.ReactNode;
        href: string | { url: string };
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...props}>
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
                            start_conversations: true,
                            mention_everyone: false,
                            view_audit_logs: false,
                            is_guest: false,
                        },
                    },
                    channels: [],
                    conversations: [],
                    people: [],
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
    });

    it('announces what is coming without offering a link to it', () => {
        mockWorkspace();

        renderRail();

        for (const label of ['Assets', 'Tasks']) {
            // A labelled gap answers "where do we keep the logo?" honestly. A
            // link would answer it with a broken page.
            expect(screen.queryByRole('link', { name: label })).toBeNull();
            expect(screen.getByRole('button', { name: label })).toHaveAttribute(
                'aria-disabled',
                'true',
            );
        }
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
