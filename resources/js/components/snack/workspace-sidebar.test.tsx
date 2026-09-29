import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WorkspaceSidebar } from '@/components/snack/workspace-sidebar';
import type { Conversation, SnackUser, WorkspacePerson } from '@/types';

const usePage = vi.hoisted(() => vi.fn());
const currentUrl = vi.hoisted(() => ({ value: '/w/snack-hq/dms' }));

vi.mock('@inertiajs/react', () => ({
    usePage,
    router: {
        on: () => () => {},
        visit: vi.fn(),
        patch: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
    },
    // The section dialogs are mounted (closed) alongside every menu that can
    // open one, so the module's form component has to exist even when no
    // dialog in the test is ever opened.
    Form: ({
        children,
    }: {
        children:
            | React.ReactNode
            | ((state: {
                  processing: boolean;
                  errors: Record<string, string>;
              }) => React.ReactNode);
    }) => (
        <form>
            {typeof children === 'function'
                ? children({ processing: false, errors: {} })
                : children}
        </form>
    ),
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

function makePerson(id: number, name: string, isBot = false): WorkspacePerson {
    return { user: makeUser(id, name), is_bot: isBot, conversation: null };
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

function makeChannel(
    id: number,
    name: string,
    channelGroupId: number | null = null,
    overrides: Record<string, unknown> = {},
) {
    return {
        id,
        name,
        slug: name.toLowerCase().replace(/\s+/g, '-'),
        description: null,
        topic: null,
        type: 'public',
        is_private: false,
        is_archived: false,
        is_default: false,
        last_message_at: null,
        is_member: true,
        is_muted: false,
        channel_group_id: channelGroupId,
        notification_level: 'all',
        last_read_message_id: null,
        created_at: null,
        ...overrides,
    };
}

function makeProject(id: number, name: string, groupId: number | null) {
    return {
        id,
        name,
        slug: name.toLowerCase().replace(/\s+/g, '-'),
        key: name.slice(0, 3).toUpperCase(),
        description: null,
        color: 'violet',
        uses_sprints: true,
        is_archived: false,
        project_group_id: groupId,
        card_fields: {},
        is_member: true,
        abilities: { contribute: true, manage: false },
        created_at: null,
    };
}

/** Anything here is merged over the default navigation payload. */
function mockWorkspace(
    overrides: Record<string, unknown> = {},
    pageProps: Record<string, unknown> = {},
) {
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
                            manage_people: false,
                            view_audit_logs: false,
                            is_guest: false,
                        },
                    },
                    channels: [],
                    channel_groups: [],
                    conversations: [group],
                    people: [
                        makePerson(2, 'Ada Lovelace'),
                        makePerson(3, 'Bo Diddley'),
                    ],
                    projects: [],
                    project_groups: [],
                    unread: {
                        channels: {},
                        conversations: {},
                        mentions: 0,
                        threads: 0,
                        notifications: 0,
                    },
                    ...overrides,
                },
            },
            auth: { user: null, profile: makeUser(1, 'Viewer') },
            ...pageProps,
        },
    });
}

beforeEach(() => {
    usePage.mockReset();
    vi.mocked(router.patch).mockClear();
    vi.mocked(router.post).mockClear();
    vi.mocked(router.delete).mockClear();
    // Which shape the sidebar takes is remembered across navigations on purpose
    // — see use-sidebar-section.ts — so it has to be cleared between tests.
    sessionStorage.clear();
    // Folded sections are remembered in localStorage, so one test's fold would
    // otherwise start the next test folded.
    localStorage.clear();
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

    it('separates people from app and webhook identities', () => {
        mockWorkspace({
            conversations: [],
            people: [
                makePerson(2, 'Ada Lovelace'),
                makePerson(3, 'Build alerts', true),
                makePerson(4, 'Standup bot', true),
            ],
        });

        render(<WorkspaceSidebar />);

        const people = screen.getByRole('list', { name: 'People' });
        const apps = screen.getByRole('list', { name: 'Apps' });
        const directMessagesSection = screen.getByRole('button', {
            name: 'Direct messages',
        });
        const appsSection = screen.getByRole('button', { name: 'Apps' });

        expect(people).toHaveTextContent('Ada Lovelace');
        expect(people).not.toHaveTextContent('Build alerts');
        expect(apps).toHaveTextContent('Build alerts');
        expect(apps).toHaveTextContent('Standup bot');
        expect(apps).not.toHaveTextContent('Ada Lovelace');
        expect(directMessagesSection.querySelectorAll('svg')).toHaveLength(2);
        expect(appsSection.querySelectorAll('svg')).toHaveLength(2);
        expect(
            directMessagesSection.querySelector('.lucide-chevron-down'),
        ).toHaveClass(
            'opacity-0',
            'group-hover/section:opacity-100',
            'group-focus-visible/section:opacity-100',
        );
        expect(
            directMessagesSection.querySelector('.lucide-message-circle'),
        ).toHaveClass(
            'group-hover/section:opacity-0',
            'group-focus-visible/section:opacity-0',
        );
        expect(
            directMessagesSection.querySelector('.lucide-message-circle')
                ?.parentElement,
        ).toHaveClass('relative', 'size-4');
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
    it('shows Files and Canvases navigation throughout the Files section', async () => {
        const user = userEvent.setup();
        currentUrl.value = '/w/snack-hq/canvases/01K2CANVAS';
        mockWorkspace(
            {},
            {
                file_canvas_sidebar: {
                    recent: [
                        {
                            id: 'file:4',
                            target_id: 4,
                            type: 'file',
                            label: 'launch-brief.pdf',
                            is_starred: false,
                            file: {
                                id: 4,
                                name: 'launch-brief.pdf',
                                mime_type: 'application/pdf',
                                size: 2048,
                                human_size: '2 KB',
                                kind: 'pdf',
                                width: null,
                                height: null,
                                is_animated: false,
                                download_url: '/files/4?signature=signed',
                                preview_url: '/files/4?inline=1&signature=signed',
                                thumbnail_url: null,
                                created_at: '2026-07-31T09:00:00+00:00',
                                uploader: null,
                                container: {
                                    kind: 'channel',
                                    id: 1,
                                    name: 'Launch',
                                    slug: 'launch',
                                    type: 'public',
                                },
                                message_id: 42,
                            },
                        },
                        {
                            id: 'canvas:01K2CANVAS',
                            target_id: '01K2CANVAS',
                            type: 'canvas',
                            label: 'Launch notes',
                            url: '/w/snack-hq/canvases/01K2CANVAS',
                            is_starred: true,
                        },
                    ],
                    starred: [
                        {
                            id: 'canvas:01K2CANVAS',
                            target_id: '01K2CANVAS',
                            type: 'canvas',
                            label: 'Launch notes',
                            url: '/w/snack-hq/canvases/01K2CANVAS',
                            is_starred: true,
                        },
                    ],
                },
            },
        );

        render(<WorkspaceSidebar />);

        expect(
            screen.getByRole('navigation', { name: 'Files and canvases' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'All files' })).toHaveAttribute(
            'href',
            '/w/snack-hq/files',
        );
        expect(screen.getByRole('link', { name: 'Canvases' })).toHaveAttribute(
            'href',
            '/w/snack-hq/canvases',
        );
        expect(screen.getByRole('link', { name: 'Canvases' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Recent')).toBeInTheDocument();
        expect(screen.getByText('Starred')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'launch-brief.pdf' }),
        ).toBeInTheDocument();
        expect(
            screen.getAllByRole('link', { name: 'Launch notes' }),
        ).toHaveLength(2);

        await user.click(
            screen.getByRole('button', { name: 'Star launch-brief.pdf' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/w/snack-hq/files/4/star',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );

        await user.click(
            screen.getAllByRole('button', { name: 'Unstar Launch notes' })[0],
        );

        expect(router.delete).toHaveBeenCalledWith(
            '/w/snack-hq/canvases/01K2CANVAS/star',
            expect.objectContaining({ preserveScroll: true }),
        );

        await user.click(screen.getByRole('button', { name: 'Create canvas' }));

        expect(router.post).toHaveBeenCalledWith(
            '/w/snack-hq/canvases',
            {},
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });

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

    it('arranges the project list under the groups holding your projects', () => {
        currentUrl.value = '/w/snack-hq/projects';

        mockWorkspace({
            projects: [
                makeProject(1, 'Pedicab Web', 7),
                makeProject(2, 'Loose Ends', null),
            ],
            // Only the groups something of yours is in — the server sends no
            // others, so an empty heading never reaches the sidebar.
            project_groups: [{ id: 7, name: 'Platform', position: 65536 }],
        });

        render(<WorkspaceSidebar />);

        expect(screen.getByText('Platform')).toBeInTheDocument();
        expect(screen.getByText('Ungrouped')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Pedicab Web/ }),
        ).toHaveAttribute('href', '/w/snack-hq/projects/pedicab-web');
    });

    it('draws a flat list when none of your projects is in a group', () => {
        currentUrl.value = '/w/snack-hq/projects';

        mockWorkspace({ projects: [makeProject(1, 'Pedicab Web', null)] });

        render(<WorkspaceSidebar />);

        // One "Ungrouped" heading over the whole list names a distinction the
        // reader cannot see, and costs a row a board could have had.
        expect(screen.queryByText('Ungrouped')).toBeNull();
        expect(screen.getByText('Pedicab Web')).toBeInTheDocument();
    });

    it('arranges the channel list under the sections you filed it in', () => {
        currentUrl.value = '/w/snack-hq/channels/general';

        mockWorkspace({
            channels: [makeChannel(1, 'general', 7), makeChannel(2, 'random')],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
        });

        render(<WorkspaceSidebar />);

        expect(screen.getByText('Team')).toBeInTheDocument();
        expect(screen.getByText('Other')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /general/ })).toHaveAttribute(
            'href',
            '/w/snack-hq/channels/general',
        );
    });

    it('draws a flat channel list when nothing is filed anywhere', () => {
        currentUrl.value = '/w/snack-hq/channels/general';

        mockWorkspace({ channels: [makeChannel(1, 'general')] });

        render(<WorkspaceSidebar />);

        // A single "Other" heading over the whole list names a
        // distinction the reader cannot see.
        expect(screen.queryByText('Other')).toBeNull();
        expect(screen.getByText('general')).toBeInTheDocument();
    });

    it('keeps an emptied section on screen, and says it is empty', () => {
        currentUrl.value = '/w/snack-hq/channels/general';

        mockWorkspace({
            channels: [],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
        });

        render(<WorkspaceSidebar />);

        expect(screen.getByText('Team')).toBeInTheDocument();
        expect(screen.getByText(/Empty\./)).toBeInTheDocument();

        // And not the "you have not joined any channels" line: they have a
        // section, so the list is theirs and merely empty.
        expect(screen.queryByText(/have not joined any channels/)).toBeNull();
    });

    it('offers each channel a way into a section', async () => {
        const user = userEvent.setup();
        currentUrl.value = '/w/snack-hq/channels/general';

        mockWorkspace({
            channels: [makeChannel(1, 'general')],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
        });

        render(<WorkspaceSidebar />);

        await user.click(
            screen.getByRole('button', { name: 'Section for #general' }),
        );

        expect(
            screen.getByRole('menuitemradio', { name: 'Team' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: 'New section…' }),
        ).toBeInTheDocument();
    });

    it('files a channel into the section it is dropped on', () => {
        currentUrl.value = '/w/snack-hq/channels/general';

        mockWorkspace({
            channels: [makeChannel(1, 'general')],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
        });

        render(<WorkspaceSidebar />);

        const row = screen.getByRole('link', { name: /general/ });
        const dataTransfer = { setData: vi.fn(), effectAllowed: 'none' };

        fireEvent.dragStart(row, { dataTransfer });

        // The heading's own section is the drop target — the whole section is,
        // since channels inside one are ordered by name and there is no slot to
        // aim at.
        const section = screen.getByText('Team').closest('div')!.parentElement!;

        fireEvent.dragOver(section, { dataTransfer });
        fireEvent.drop(section, { dataTransfer });

        expect(router.patch).toHaveBeenCalledWith(
            '/w/snack-hq/channels/general/group',
            { channel_group_id: 7 },
            { preserveScroll: true },
        );
    });

    it('unfiles a channel dropped back on the leftovers', () => {
        currentUrl.value = '/w/snack-hq/channels/general';

        mockWorkspace({
            channels: [makeChannel(1, 'general', 7)],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
        });

        render(<WorkspaceSidebar />);

        const dataTransfer = { setData: vi.fn(), effectAllowed: 'none' };

        fireEvent.dragStart(screen.getByRole('link', { name: /general/ }), {
            dataTransfer,
        });

        const leftovers = screen.getByText('Other').parentElement!;

        fireEvent.dragOver(leftovers, { dataTransfer });
        fireEvent.drop(leftovers, { dataTransfer });

        // Null is the whole of "take it out of its section", the same null the
        // ⋯ menu sends.
        expect(router.patch).toHaveBeenCalledWith(
            '/w/snack-hq/channels/general/group',
            { channel_group_id: null },
            { preserveScroll: true },
        );
    });

    it('ignores a drop back onto the section the channel came from', () => {
        currentUrl.value = '/w/snack-hq/channels/general';

        mockWorkspace({
            channels: [makeChannel(1, 'general', 7)],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
        });

        render(<WorkspaceSidebar />);

        const dataTransfer = { setData: vi.fn(), effectAllowed: 'none' };

        fireEvent.dragStart(screen.getByRole('link', { name: /general/ }), {
            dataTransfer,
        });

        const section = screen.getByText('Team').closest('div')!.parentElement!;

        fireEvent.drop(section, { dataTransfer });

        // A request that changes nothing, and a redraw of the sidebar to show
        // the same arrangement.
        expect(router.patch).not.toHaveBeenCalled();
    });

    it('folds a section away, and remembers that it is folded', async () => {
        const user = userEvent.setup();
        currentUrl.value = '/w/snack-hq/channels/announcements';

        mockWorkspace({
            channels: [makeChannel(1, 'general', 7)],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
        });

        const { unmount } = render(<WorkspaceSidebar />);

        await user.click(screen.getByRole('button', { name: /^Team/ }));

        expect(screen.queryByRole('link', { name: /general/ })).toBeNull();

        // The count is what the fold is holding back, so a folded section still
        // says how much is in it.
        expect(screen.getByRole('button', { name: /^Team/ })).toHaveTextContent(
            '1',
        );

        unmount();
        render(<WorkspaceSidebar />);

        // Folding is a standing preference, not a per-visit one.
        expect(screen.queryByRole('link', { name: /general/ })).toBeNull();
    });

    it('keeps unread and current channels visible through a fold', async () => {
        const user = userEvent.setup();
        currentUrl.value = '/w/snack-hq/channels/general';

        mockWorkspace({
            channels: [
                makeChannel(1, 'general', 7),
                makeChannel(2, 'random', 7),
                makeChannel(3, 'engineering', 7),
            ],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
            unread: {
                channels: { 2: { messages: 3, mentions: 0 } },
                conversations: {},
                mentions: 0,
                threads: 0,
                notifications: 0,
            },
        });

        render(<WorkspaceSidebar />);

        await user.click(screen.getByRole('button', { name: /^Team/ }));

        // Folding says "I do not need to see these", not "stop telling me
        // things" — a swallowed unread badge would make the fold unusable.
        expect(
            screen.getByRole('link', { name: /random/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /general/ }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /engineering/ })).toBeNull();
    });

    it('unfolds a section when a channel is dropped into it', async () => {
        const user = userEvent.setup();
        currentUrl.value = '/w/snack-hq/channels/announcements';

        mockWorkspace({
            channels: [makeChannel(1, 'general'), makeChannel(2, 'random', 7)],
            channel_groups: [{ id: 7, name: 'Team', position: 65536 }],
        });

        render(<WorkspaceSidebar />);

        await user.click(screen.getByRole('button', { name: /^Team/ }));
        expect(screen.queryByRole('link', { name: /random/ })).toBeNull();

        const dataTransfer = { setData: vi.fn(), effectAllowed: 'none' };

        fireEvent.dragStart(screen.getByRole('link', { name: /general/ }), {
            dataTransfer,
        });

        const section = screen
            .getByRole('button', { name: /^Team/ })
            .closest('div')!.parentElement!;

        fireEvent.drop(section, { dataTransfer });

        // Dropping something into a folded section and seeing nothing happen is
        // the one way this gesture can look broken.
        expect(
            screen.getByRole('link', { name: /random/ }),
        ).toBeInTheDocument();
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
