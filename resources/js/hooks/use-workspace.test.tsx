import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    useCurrentWorkspace,
    useSidebarChannels,
    useSidebarConversations,
    useUnreadState,
    useWorkspace,
    useWorkspaceAbilities,
} from '@/hooks/use-workspace';
import type { Channel, Conversation, CurrentWorkspace } from '@/types';

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

function makeCurrent(
    channels: Channel[] = [],
    conversations: Conversation[] = [],
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
                start_conversations: true,
                mention_everyone: false,
                view_audit_logs: false,
                is_guest: false,
            },
        },
        channels,
        conversations,
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

    it('returns empty lists outside a workspace', () => {
        mockPage(null);

        const { result } = renderHook(() => useSidebarConversations());

        expect(result.current).toEqual({ conversations: [], muted: [] });
    });
});
