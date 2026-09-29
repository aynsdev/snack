import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    useCurrentWorkspace,
    useSidebarChannels,
    useSidebarConversations,
    useSidebarPeople,
    useUnreadState,
    useWorkspace,
    useWorkspaceAbilities,
} from '@/hooks/use-workspace';
import type {
    Channel,
    ChannelGroup,
    Conversation,
    CurrentWorkspace,
    WorkspacePerson,
} from '@/types';

const usePage = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({ usePage }));

function makeChannel(overrides: Partial<Channel> = {}): Channel {
    return {
        id: 1,
        name: 'general',
        slug: 'general',
        description: null,
        topic: null,
        type: 'public',
        is_private: false,
        is_archived: false,
        is_default: false,
        last_message_at: null,
        is_member: true,
        is_muted: false,
        channel_group_id: null,
        notification_level: 'mentions',
        last_read_message_id: null,
        created_at: null,
        ...overrides,
    };
}

function makeConversation(overrides: Partial<Conversation> = {}): Conversation {
    return {
        id: 'conv_01HZX',
        type: 'direct',
        name: null,
        display_name: 'Bo Diddley',
        participants: [],
        last_message_at: null,
        is_muted: false,
        notification_level: 'all',
        last_read_message_id: null,
        has_left: false,
        created_at: null,
        ...overrides,
    };
}

function makePerson(
    id: number,
    name: string,
    conversation: Conversation | null = null,
): WorkspacePerson {
    return {
        user: {
            id,
            name,
            username: name.toLowerCase(),
            initials: name.slice(0, 1),
            avatar_url: null,
            title: null,
            department: null,
            presence_status: 'online',
            status_emoji: null,
            status_message: null,
        },
        is_bot: false,
        conversation,
    };
}

function makeChannelGroup(overrides: Partial<ChannelGroup> = {}): ChannelGroup {
    return { id: 1, name: 'Team', position: 65536, ...overrides };
}

function makeCurrent(
    channels: Channel[] = [],
    conversations: Conversation[] = [],
    people: WorkspacePerson[] = [],
    channelGroups: ChannelGroup[] = [],
): CurrentWorkspace {
    return {
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
        channels,
        channel_groups: channelGroups,
        conversations,
        people,
        projects: [],
        project_groups: [],
        unread: {
            channels: { 1: { messages: 3, mentions: 1 } },
            conversations: { conv_01HZX: { messages: 2, mentions: 2 } },
            mentions: 1,
            threads: 0,
            notifications: 2,
        },
    };
}

function mockPage(current: CurrentWorkspace | null) {
    usePage.mockReturnValue({
        props: {
            snack: { workspaces: [], current },
            auth: { user: null, profile: null },
        },
    });
}

beforeEach(() => {
    usePage.mockReset();
});

describe('useCurrentWorkspace', () => {
    it('returns null outside a workspace-scoped page', () => {
        mockPage(null);

        const { result } = renderHook(() => useCurrentWorkspace());

        expect(result.current).toBeNull();
    });

    it('returns the workspace payload inside one', () => {
        mockPage(makeCurrent());

        const { result } = renderHook(() => useCurrentWorkspace());

        expect(result.current?.workspace.slug).toBe('snack-hq');
    });
});

describe('useWorkspace', () => {
    it('throws a directed error when there is no workspace', () => {
        mockPage(null);

        // Failing loudly here beats an undefined read three components deep.
        expect(() => renderHook(() => useWorkspace())).toThrow(
            /outside a workspace-scoped page/,
        );
    });

    it('exposes the ability flags computed server-side', () => {
        mockPage(makeCurrent());

        const { result } = renderHook(() => useWorkspaceAbilities());

        expect(result.current.create_channels).toBe(true);
        expect(result.current.administer).toBe(false);
    });
});

describe('useSidebarChannels', () => {
    it('separates muted channels from the rest', () => {
        mockPage(
            makeCurrent([
                makeChannel({ id: 1, slug: 'general' }),
                makeChannel({ id: 2, slug: 'random', is_muted: true }),
            ]),
        );

        const { result } = renderHook(() => useSidebarChannels());

        expect(result.current.channels.map((c) => c.slug)).toEqual(['general']);
        expect(result.current.muted.map((c) => c.slug)).toEqual(['random']);
    });

    it('returns empty groupings when there is no workspace', () => {
        mockPage(null);

        const { result } = renderHook(() => useSidebarChannels());

        expect(result.current.channels).toEqual([]);
        expect(result.current.muted).toEqual([]);
        expect(result.current.sections).toEqual([]);
    });

    it('buckets channels under the sections they are filed in', () => {
        mockPage(
            makeCurrent(
                [
                    makeChannel({
                        id: 1,
                        slug: 'general',
                        channel_group_id: 7,
                    }),
                    makeChannel({ id: 2, slug: 'random' }),
                ],
                [],
                [],
                [makeChannelGroup({ id: 7, name: 'Team' })],
            ),
        );

        const { result } = renderHook(() => useSidebarChannels());

        expect(
            result.current.sections.map((section) => [
                section.group?.name ?? null,
                section.channels.map((channel) => channel.slug),
            ]),
        ).toEqual([
            ['Team', ['general']],
            [null, ['random']],
        ]);
    });

    it('keeps a section that has nothing in it', () => {
        mockPage(
            makeCurrent(
                [makeChannel({ id: 1, slug: 'general' })],
                [],
                [],
                [makeChannelGroup({ id: 7, name: 'Team' })],
            ),
        );

        const { result } = renderHook(() => useSidebarChannels());

        // The sidebar is where sections are made and filled. One that vanished
        // the moment its last channel left would take with it the only handle
        // for putting anything back into it.
        expect(result.current.sections[0]).toEqual({
            group: makeChannelGroup({ id: 7, name: 'Team' }),
            channels: [],
        });
    });

    it('sends a muted channel to the muted shelf rather than its section', () => {
        mockPage(
            makeCurrent(
                [
                    makeChannel({
                        id: 1,
                        slug: 'random',
                        channel_group_id: 7,
                        is_muted: true,
                    }),
                ],
                [],
                [],
                [makeChannelGroup({ id: 7, name: 'Team' })],
            ),
        );

        const { result } = renderHook(() => useSidebarChannels());

        expect(result.current.muted.map((c) => c.slug)).toEqual(['random']);
        expect(result.current.sections[0].channels).toEqual([]);
    });

    it('treats a channel filed under a section that is gone as unfiled', () => {
        mockPage(makeCurrent([makeChannel({ id: 1, channel_group_id: 99 })]));

        const { result } = renderHook(() => useSidebarChannels());

        // A deleted section nulls the assignment server-side, but a payload
        // read mid-flight must not drop the channel out of the sidebar.
        expect(result.current.sections).toEqual([
            { group: null, channels: result.current.channels },
        ]);
    });
});

describe('useUnreadState', () => {
    it('exposes per-channel counts', () => {
        mockPage(makeCurrent([makeChannel()]));

        const { result } = renderHook(() => useUnreadState());

        expect(result.current.channels[1]).toEqual({
            messages: 3,
            mentions: 1,
        });
        expect(result.current.mentions).toBe(1);
    });

    it('exposes per-conversation counts', () => {
        mockPage(makeCurrent([], [makeConversation()]));

        const { result } = renderHook(() => useUnreadState());

        // A DM's mention count tracks its message count: everything said in a
        // conversation you are in is addressed to you.
        expect(result.current.conversations.conv_01HZX).toEqual({
            messages: 2,
            mentions: 2,
        });
    });

    it('falls back to a zeroed state outside a workspace', () => {
        mockPage(null);

        const { result } = renderHook(() => useUnreadState());

        expect(result.current).toEqual({
            channels: {},
            conversations: {},
            mentions: 0,
            threads: 0,
            notifications: 0,
        });
    });
});

describe('useSidebarConversations', () => {
    it('separates muted conversations from the rest', () => {
        mockPage(
            makeCurrent(
                [],
                [
                    makeConversation({ id: 'conv_a' }),
                    makeConversation({ id: 'conv_b', is_muted: true }),
                ],
            ),
        );

        const { result } = renderHook(() => useSidebarConversations());

        expect(result.current.conversations.map((c) => c.id)).toEqual([
            'conv_a',
        ]);
        expect(result.current.muted.map((c) => c.id)).toEqual(['conv_b']);
    });

    it('separates groups from direct conversations', () => {
        mockPage(
            makeCurrent(
                [],
                [
                    makeConversation({ id: 'conv_direct' }),
                    makeConversation({ id: 'conv_group', type: 'group' }),
                    makeConversation({
                        id: 'conv_muted_group',
                        type: 'group',
                        is_muted: true,
                    }),
                ],
            ),
        );

        const { result } = renderHook(() => useSidebarConversations());

        /*
         * Direct conversations are rendered from the people roster, so the
         * sidebar's own list must not carry them too — that would be the same
         * thread twice, once titled by the person and once by the row.
         */
        expect(result.current.groups.map((c) => c.id)).toEqual(['conv_group']);
        expect(result.current.mutedGroups.map((c) => c.id)).toEqual([
            'conv_muted_group',
        ]);
    });

    it('leaves a muted direct message out of the muted groups', () => {
        mockPage(
            makeCurrent(
                [],
                [makeConversation({ id: 'conv_direct', is_muted: true })],
            ),
        );

        const { result } = renderHook(() => useSidebarConversations());

        // It stays on its person's row wearing the bell icon; moving it would
        // drop a member out of a list of all the members.
        expect(result.current.mutedGroups).toEqual([]);
        expect(result.current.muted.map((c) => c.id)).toEqual(['conv_direct']);
    });

    it('returns empty lists outside a workspace', () => {
        mockPage(null);

        const { result } = renderHook(() => useSidebarConversations());

        expect(result.current).toEqual({
            conversations: [],
            muted: [],
            groups: [],
            mutedGroups: [],
        });
    });
});

describe('useSidebarPeople', () => {
    it('passes the roster through in the order the server sent it', () => {
        mockPage(
            makeCurrent(
                [],
                [],
                [
                    makePerson(2, 'Bo', makeConversation({ id: 'conv_bo' })),
                    makePerson(3, 'Cy'),
                ],
            ),
        );

        const { result } = renderHook(() => useSidebarPeople());

        expect(result.current.map((person) => person.user.name)).toEqual([
            'Bo',
            'Cy',
        ]);
        // Somebody never messaged is still in the list, with no thread behind
        // them — that is what makes this a roster and not a list of threads.
        expect(result.current[1].conversation).toBeNull();
    });

    it('returns an empty roster outside a workspace', () => {
        mockPage(null);

        const { result } = renderHook(() => useSidebarPeople());

        expect(result.current).toEqual([]);
    });
});
