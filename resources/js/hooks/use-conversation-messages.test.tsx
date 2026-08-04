import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { withQueryClient } from '@/tests/query';
import type { Conversation, Message, MessagePage, SnackUser } from '@/types';

/**
 * The timeline's behaviour is proven in use-channel-messages.test.tsx, since both
 * containers run on the same {@see useMessageTimeline}. What is worth asserting
 * here is the part that differs: that a conversation follows *its* broadcast
 * channel, posts to *its* endpoints, and knows when there is no read state to
 * advance.
 */
const subscriptions = vi.hoisted(
    () => new Map<string, Map<string, (payload: unknown) => void>>(),
);

vi.mock('@laravel/echo-react', () => ({
    useEchoPresence: (
        channel: string,
        event: string,
        callback: (payload: unknown) => void,
    ) => {
        const events = subscriptions.get(channel) ?? new Map();
        events.set(event, callback);
        subscriptions.set(channel, events);

        return { leave: vi.fn(), leaveChannel: vi.fn() };
    },
    configureEcho: vi.fn(),
}));

const api = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
    api,
    ApiError: class ApiError extends Error {},
}));

const { useConversationMessages } =
    await import('@/hooks/use-conversation-messages');

const currentUser: SnackUser = {
    id: 1,
    name: 'Ada',
    username: 'ada',
    initials: 'A',
    avatar_url: null,
    title: null,
    department: null,
    presence_status: 'online',
    status_emoji: null,
    status_message: null,
};

const conversation = {
    id: 'conv_01HZX',
    type: 'direct',
    has_left: false,
    is_muted: false,
    participants: [],
} as unknown as Conversation;

function makeMessage(id: number, overrides: Partial<Message> = {}): Message {
    return {
        id,
        workspace_id: 1,
        channel_id: null,
        conversation_id: 'conv_01HZX',
        parent_message_id: null,
        body: `message ${id}`,
        type: 'text',
        sender: currentUser,
        reply_count: 0,
        last_reply_at: null,
        last_reply_id: null,
        reactions: [],
        is_pinned: false,
        has_attachments: false,
        attachments: [],
        is_edited: false,
        edited_at: null,
        created_at: '2026-07-30T10:00:00Z',
        can: { update: true, delete: true, pin: true },
        ...overrides,
    };
}

function setup(
    overrides: Partial<Conversation> = {},
    initial: Partial<MessagePage> = {},
) {
    return renderHook(
        () =>
            useConversationMessages({
                conversation: { ...conversation, ...overrides },
                workspaceSlug: 'snack-hq',
                initial: {
                    messages: [makeMessage(1)],
                    has_more: false,
                    next_cursor: null,
                    ...initial,
                },
                currentUser,
            }),
        // The timeline keeps its messages in the query cache; without a client
        // above it the hook has nowhere to read or write them.
        { wrapper: withQueryClient() },
    );
}

beforeEach(() => {
    subscriptions.clear();
    vi.clearAllMocks();
});

describe('useConversationMessages', () => {
    it('follows the conversation’s own broadcast channel', () => {
        setup();

        // A typo in a channel name fails silently — the subscription simply
        // never receives anything — which is exactly the bug worth pinning down.
        expect([...subscriptions.keys()]).toEqual(['conversation.conv_01HZX']);
    });

    it('applies a message arriving on that channel', () => {
        const { result } = setup();

        act(() => {
            subscriptions
                .get('conversation.conv_01HZX')
                ?.get('.message.sent')?.({
                message: makeMessage(2),
            });
        });

        expect(result.current.messages.map((m) => m.id)).toEqual([1, 2]);
    });

    it('posts to the conversation message endpoint', async () => {
        api.post.mockResolvedValue({ message: makeMessage(2) });

        const { result } = setup();

        await act(async () => {
            await result.current.send('Lunch?');
        });

        expect(api.post).toHaveBeenCalledWith(
            '/w/snack-hq/dms/conv_01HZX/messages',
            {
                body: 'Lunch?',
                attachment_ids: [],
            },
        );
    });

    it('requests older history from the conversation endpoint with a cursor', async () => {
        api.get.mockResolvedValue({
            messages: [makeMessage(0)],
            has_more: false,
            next_cursor: null,
        });

        const { result } = setup({}, { has_more: true, next_cursor: 1 });

        await act(async () => {
            await result.current.loadOlder();
        });

        expect(api.get).toHaveBeenCalledWith(
            '/w/snack-hq/dms/conv_01HZX/messages?before=1',
        );
    });

    it('advances the read pointer for an active participant', () => {
        const { result } = setup();

        act(() => result.current.markAsRead());

        expect(api.post).toHaveBeenCalledWith(
            '/w/snack-hq/dms/conv_01HZX/read',
            {
                last_read_message_id: 1,
            },
        );
    });

    it('does not advance a read pointer for someone who left', () => {
        const { result } = setup({ has_left: true });

        act(() => result.current.markAsRead());

        // They still read the history, but they are no longer badged about it,
        // so there is nothing to advance.
        expect(api.post).not.toHaveBeenCalled();
    });

    it('edits and deletes through the workspace-level message routes', async () => {
        api.patch.mockResolvedValue({
            message: makeMessage(1, { body: 'ok' }),
        });
        api.delete.mockResolvedValue({ id: 1 });

        const { result } = setup();

        await act(async () => {
            await result.current.edit(1, 'ok');
        });

        // A message id already names its container, so these are addressed
        // directly rather than through the conversation.
        expect(api.patch).toHaveBeenCalledWith('/w/snack-hq/messages/1', {
            body: 'ok',
        });

        await act(async () => {
            await result.current.remove(1);
        });

        expect(api.delete).toHaveBeenCalledWith('/w/snack-hq/messages/1');
    });
});
