import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WorkspaceSidebar } from '@/components/snack/workspace-sidebar';
import type { Conversation, SnackUser, WorkspacePerson } from '@/types';

const usePage = vi.hoisted(() => vi.fn());
const currentUrl = vi.hoisted(() => ({ value: '/w/snack-hq/dms' }));

vi.mock('@inertiajs/react', () => ({
    usePage,
    router: { on: () => () => {} },
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

vi.mock('@/hooks/use-current-url', () => ({
    useCurrentUrl: () => ({ currentUrl: currentUrl.value }),
}));

// Asks the browser for notification permission on mount, which jsdom has no
// answer for and this test has no interest in.
vi.mock('@/components/snack/notification-permission-prompt', () => ({
    NotificationPermissionPrompt: () => null,
}));

function makeUser(id: number, name: string): SnackUser {
    return {
        id,
        name,
        username: name.toLowerCase().replace(/\s+/g, ''),
        initials: name.slice(0, 1),
        avatar_url: null,
        title: null,
        department: null,
        presence_status: 'online',
        status_emoji: null,
        status_message: null,
    };
}

function makePerson(id: number, name: string): WorkspacePerson {
    return { user: makeUser(id, name), conversation: null };
}

const group: Conversation = {
    id: 'conv_group',
    type: 'group',
    name: 'Launch crew',
    display_name: 'Launch crew',
    participants: [],
    last_message_at: null,
    is_muted: false,
    notification_level: 'all',
    last_read_message_id: null,
    has_left: false,
    created_at: null,
};

function mockWorkspace() {
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
                    conversations: [group],
                    people: [
                        makePerson(2, 'Ada Lovelace'),
                        makePerson(3, 'Bo Diddley'),
                    ],
                    unread: {
                        channels: {},
                        conversations: {},
                        mentions: 0,
                        threads: 0,
                        notifications: 0,
                    },
                },
            },
            auth: { user: null, profile: makeUser(1, 'Viewer') },
        },
    });
}

beforeEach(() => {
    usePage.mockReset();
    // Which shape the sidebar takes is remembered across navigations on purpose
    // — see use-sidebar-section.ts — so it has to be cleared between tests.
    sessionStorage.clear();
    currentUrl.value = '/w/snack-hq/dms';
    mockWorkspace();
});

describe('WorkspaceSidebar inside direct messages', () => {
    it('offers a filter box rather than the global search button', () => {
        render(<WorkspaceSidebar />);

        /*
         * Two different questions: this one narrows the list of people in front
         * of you, where global search looks through what people wrote. Sending
         * a name to the search page would answer the second when the first was
         * asked — and cost a page load to do it.
         */
        expect(
            screen.getByRole('textbox', { name: 'Filter direct messages' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /^Search/ })).toBeNull();
    });

    it('narrows the list as you type, without navigating', async () => {
        const user = userEvent.setup();

        render(<WorkspaceSidebar />);

        await user.type(
            screen.getByRole('textbox', { name: 'Filter direct messages' }),
            'ada',
        );

        expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
        expect(screen.queryByText('Bo Diddley')).toBeNull();
        expect(screen.queryByText('Launch crew')).toBeNull();
    });

    it('matches a group by its name too', async () => {
        const user = userEvent.setup();

        render(<WorkspaceSidebar />);

        await user.type(
            screen.getByRole('textbox', { name: 'Filter direct messages' }),
            'launch',
        );

        expect(screen.getByText('Launch crew')).toBeInTheDocument();
        expect(screen.queryByText('Ada Lovelace')).toBeNull();
    });

    it('points at message search when no name matches', async () => {
        const user = userEvent.setup();

        render(<WorkspaceSidebar />);

        await user.type(
            screen.getByRole('textbox', { name: 'Filter direct messages' }),
            'budget',
        );

        // "Nobody by that name" is a different answer from "nothing said that",
        // and the reader may well have meant the second — so the way to it is
        // offered rather than silently substituted.
        expect(
            screen.getByRole('link', { name: /Search messages instead/ }),
        ).toHaveAttribute('href', '/w/snack-hq/search?q=budget');
    });

    it('clears back to the whole roster on Escape', async () => {
        const user = userEvent.setup();

        render(<WorkspaceSidebar />);

        const box = screen.getByRole('textbox', {
            name: 'Filter direct messages',
        });

        await user.type(box, 'ada');
        await user.type(box, '{Escape}');

        expect(box).toHaveValue('');
        expect(screen.getByText('Bo Diddley')).toBeInTheDocument();
    });

    it('leaves the channel list out of this shape entirely', () => {
        render(<WorkspaceSidebar />);

        expect(screen.queryByText('Channels')).toBeNull();
        expect(screen.queryByText('Browse channels')).toBeNull();
    });
});

describe('WorkspaceSidebar outside direct messages', () => {
    it('keeps the global search button', () => {
        currentUrl.value = '/w/snack-hq/channels/general';

        render(<WorkspaceSidebar />);

        expect(screen.getByRole('link', { name: /^Search/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/search',
        );
        expect(
            screen.queryByRole('textbox', { name: 'Filter direct messages' }),
        ).toBeNull();
    });

    it('stands its ground when a DM is opened from the roster inside it', () => {
        currentUrl.value = '/w/snack-hq/channels/general';

        const { unmount } = render(<WorkspaceSidebar />);

        unmount();

        // The DM roster is the bottom half of this sidebar, so a click in it
        // used to replace the whole column — channels, Threads, Huddles — with
        // the DM list, rearranging the thing the reader had just clicked in.
        currentUrl.value = '/w/snack-hq/dms/01HZXCONVERSATION';

        render(<WorkspaceSidebar />);

        expect(screen.getByText('Channels')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /^Search/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('textbox', { name: 'Filter direct messages' }),
        ).toBeNull();
    });

    it('gives way to the DM list for a conversation opened from it', () => {
        currentUrl.value = '/w/snack-hq/dms';

        const { unmount } = render(<WorkspaceSidebar />);

        unmount();

        currentUrl.value = '/w/snack-hq/dms/01HZXCONVERSATION';

        render(<WorkspaceSidebar />);

        expect(
            screen.getByRole('textbox', { name: 'Filter direct messages' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('Channels')).toBeNull();
    });
});
