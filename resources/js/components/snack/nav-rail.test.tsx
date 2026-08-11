import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NavRail } from '@/components/snack/nav-rail';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { UnreadState } from '@/types';

const usePage = vi.hoisted(() => vi.fn());
const routerPatch = vi.hoisted(() => vi.fn());
const currentUrl = vi.hoisted(() => ({
    value: '/w/snack-hq/channels/general',
}));

vi.mock('@inertiajs/react', () => ({
    usePage,
    Link: ({
        children,
        href,
        onClick,
        prefetch,
        ...props
    }: {
        children: React.ReactNode;
        href: string | { url: string };
        onClick?: (event: React.MouseEvent<HTMLElement>) => void;
        prefetch?: boolean;
    }) => {
        void prefetch;

        return (
            <a
                href={typeof href === 'string' ? href : href.url}
                // The real Link intercepts the click and visits over XHR;
                // without this jsdom tries to navigate the document instead.
                onClick={(event) => {
                    event.preventDefault();
                    onClick?.(event);
                }}
                {...props}
            >
                {children}
            </a>
        );
    },
    Form: ({
        children,
    }: {
        children: (props: {
            errors: Record<string, string>;
            processing: boolean;
        }) => React.ReactNode;
    }) => <form>{children({ errors: {}, processing: false })}</form>,
    router: {
        flushAll: vi.fn(),
        patch: routerPatch,
    },
}));

// The rail reads the URL to decide what is current; the real hook subscribes to
// the Inertia router, which needs a running app.
vi.mock('@/hooks/use-current-url', () => ({
    useCurrentUrl: () => ({ currentUrl: currentUrl.value }),
}));

function mockWorkspace(
    unread: Partial<UnreadState> = {},
    {
        signedIn = false,
        statusMessage = null,
        statusEmoji = null,
    }: {
        signedIn?: boolean;
        statusMessage?: string | null;
        statusEmoji?: string | null;
    } = {},
) {
    const user = {
        id: 1,
        name: 'Ada Lovelace',
        email: 'ada@example.com',
        initials: 'AL',
        avatar_url: null,
        presence_status: 'online',
        status_message: statusMessage,
        status_emoji: statusEmoji,
        email_verified_at: '2026-08-10T00:00:00.000000Z',
        created_at: '2026-08-10T00:00:00.000000Z',
        updated_at: '2026-08-10T00:00:00.000000Z',
    };

    usePage.mockReturnValue({
        props: {
            snack: {
                workspaces: [
                    {
                        id: 1,
                        name: 'Snack HQ',
                        slug: 'snack-hq',
                        initials: 'SH',
                        logo_url: null,
                    },
                ],
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
            auth: {
                user: signedIn ? user : null,
                profile: signedIn ? user : null,
            },
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
    routerPatch.mockReset();
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
        expect(screen.queryByRole('link', { name: /Canvases/ })).toBeNull();
        expect(screen.getByRole('link', { name: /Projects/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/projects',
        );
    });

    it('links to the asset library', () => {
        mockWorkspace();

        renderRail();

        // "Where do we keep the logo?" now has a destination rather than a
        // labelled gap — see the assets/index page.
        expect(screen.getByRole('link', { name: /Assets/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/assets',
        );
    });

    it('keeps theme and profile controls aligned in the section rail', () => {
        mockWorkspace({}, { signedIn: true });

        renderRail();

        expect(
            screen.getByRole('button', {
                name: 'Change color theme. Current theme: Original',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Your account and status' }),
        ).toBeInTheDocument();
    });

    it('shows the single workspace identity above Home', () => {
        mockWorkspace();

        renderRail();

        const workspace = screen.getByRole('link', { name: 'Snack HQ' });

        expect(workspace).toHaveAttribute('href', '/w/snack-hq');
        expect(within(workspace).getByText('SH')).toBeInTheDocument();
    });

    it('opens account actions for status, notifications, and preferences', async () => {
        const user = userEvent.setup();
        mockWorkspace({}, { signedIn: true });

        renderRail();
        await user.click(
            screen.getByRole('button', { name: 'Your account and status' }),
        );

        expect(
            screen.getByRole('menuitem', { name: 'Set a status' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: 'Notifications' }),
        ).toHaveAttribute('href', '/w/snack-hq/notification-preferences');
        expect(
            screen.getByRole('menuitem', { name: 'Profile' }),
        ).toHaveAttribute('href', '/settings/profile');
        expect(
            screen.getByRole('menuitem', { name: 'Preferences' }),
        ).toHaveAttribute('href', '/settings/appearance');
        expect(
            within(screen.getByRole('menu')).getAllByText('Active'),
        ).toHaveLength(1);

        await user.click(
            screen.getByRole('menuitem', { name: 'Set a status' }),
        );

        const dialog = screen.getByRole('dialog', { name: 'Set your status' });

        expect(dialog).toBeInTheDocument();

        await user.click(
            within(dialog).getByRole('button', { name: /In a meeting/ }),
        );

        expect(within(dialog).getByLabelText('Status')).toHaveValue(
            'In a meeting',
        );
        expect(within(dialog).getByLabelText('Clear status')).toHaveValue(
            'one_hour',
        );
        expect(
            within(dialog).getByRole('button', {
                name: 'Choose status emoji. Current emoji: 🗓️',
            }),
        ).toBeInTheDocument();

        await user.click(
            within(dialog).getByRole('button', {
                name: 'Choose status emoji. Current emoji: 🗓️',
            }),
        );
        await user.click(screen.getByRole('button', { name: 'Airplane' }));

        expect(
            within(dialog).getByRole('button', {
                name: 'Choose status emoji. Current emoji: ✈️',
            }),
        ).toBeInTheDocument();
    });

    it('shows an existing status once and offers a separate clear action', async () => {
        const user = userEvent.setup();
        mockWorkspace(
            {},
            {
                signedIn: true,
                statusMessage: 'In a meeting',
                statusEmoji: '🗓️',
            },
        );

        renderRail();
        await user.click(
            screen.getByRole('button', { name: 'Your account and status' }),
        );

        const menu = screen.getByRole('menu');

        expect(within(menu).getAllByText('In a meeting')).toHaveLength(1);
        expect(
            within(menu).getByRole('menuitem', { name: /In a meeting/ }),
        ).toBeInTheDocument();

        await user.click(
            within(menu).getByRole('menuitem', { name: 'Clear status' }),
        );

        expect(routerPatch).toHaveBeenCalledWith(
            '/settings/status',
            { clear: true },
            { preserveScroll: true },
        );
    });

    it('marks a folder inside the library as Assets', () => {
        currentUrl.value = '/w/snack-hq/assets/folders/12';
        mockWorkspace();

        renderRail();

        // The rail names a section, and every shelf in the library is inside
        // it — arriving at one from the sidebar must not make the section go
        // dark.
        expect(screen.getByRole('link', { name: /Assets/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('keeps Files active while viewing a canvas document', () => {
        currentUrl.value = '/w/snack-hq/canvases/01K2CANVAS';
        mockWorkspace();

        renderRail();

        expect(screen.getByRole('link', { name: /Files/ })).toHaveAttribute(
            'aria-current',
            'page',
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
