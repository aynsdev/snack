import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Message, SnackUser, ThreadPage } from '@/types';

/**
 * Echo subscriptions are captured rather than connected, so a test can deliver a
 * broadcast by hand and assert how the panel reconciles it.
 *
 * A thread subscribes with `useEcho` (private), not `useEchoPresence` — it has
 * no roster to render, and its authorization callback answers with a bool. The
 * mock only provides the one the hook is expected to use, so reaching for the
 * presence variant would fail loudly here rather than silently never connecting
 * in the browser.
 */
const listeners = vi.hoisted(
    () => new Map<string, (payload: unknown) => void>(),
);

vi.mock('@laravel/echo-react', () => ({
    useEcho: (
        _channel: string,
        event: string,
        callback: (payload: unknown) => void,
    ) => {
        listeners.set(event, callback);

        return { leave: vi.fn(), leaveChannel: vi.fn() };
    },
    // lib/echo.ts calls this at module load to configure the transport.
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

const { useThread } = await import('@/hooks/use-thread');

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

function makeMessage(id: number, overrides: Partial<Message> = {}): Message {
    return {
        id,
        workspace_id: 1,
        channel_id: 7,
        conversation_id: null,
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
        created_at: '2026-07-31T10:00:00+00:00',
        can: { update: true, delete: true, pin: true },
        ...overrides,
    };
}

function makePage(overrides: Partial<ThreadPage> = {}): ThreadPage {
    return {
        root: makeMessage(10, { reply_count: 1 }),
        replies: [makeMessage(11, { parent_message_id: 10 })],
        has_more: false,
        next_cursor: null,
        subscription: { is_subscribed: true, last_read_reply_id: 11 },
        ...overrides,
    };
}

function mount(page: ThreadPage = makePage()) {
    api.get.mockResolvedValue(page);

    return renderHook(() =>
        useThread({
            rootId: 10,
            workspaceSlug: 'acme',
            sendUrl: '/w/acme/channels/general/messages',
            currentUser,
        }),
    );
}

beforeEach(() => {
    listeners.clear();
    vi.clearAllMocks();
});

describe('useThread', () => {
    it('loads the root and its replies', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        expect(result.current.root?.id).toBe(10);
        expect(result.current.replies.map((r) => r.id)).toEqual([11]);
        expect(result.current.subscription.is_subscribed).toBe(true);
    });

    it('posts a reply against the container with the parent id', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        api.post.mockResolvedValue({
            message: makeMessage(12, { parent_message_id: 10 }),
        });

        await act(async () => {
            await result.current.reply('Snack.');
        });

        // One send path: the container's endpoint, carrying the parent.
        expect(api.post).toHaveBeenCalledWith(
            '/w/acme/channels/general/messages',
            { body: 'Snack.', parent_message_id: 10, attachment_ids: [] },
        );

        expect(result.current.replies.map((r) => r.id)).toEqual([11, 12]);
        expect(result.current.pending).toHaveLength(0);
    });

    it('keeps a failed reply on screen so it can be retried', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        api.post.mockRejectedValue(new Error('offline'));

        await act(async () => {
            await result.current.reply('Snack.');
        });

        // Dropping it silently is the worst outcome: the author watched it
        // appear and has already moved on.
        expect(result.current.pending).toHaveLength(1);
        expect(result.current.pending[0].failed).toBe(true);
        expect(result.current.replies).toHaveLength(1);
    });

    it('de-duplicates a reply that arrives by both routes', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        const reply = makeMessage(12, { parent_message_id: 10 });

        api.post.mockResolvedValue({ message: reply });

        await act(async () => {
            await result.current.reply('Snack.');
        });

        // The author's own broadcast is not suppressed — their other tabs need
        // it — so the same reply can land twice, in either order.
        act(() => {
            listeners.get('.message.sent')?.({ message: reply });
        });

        expect(result.current.replies.map((r) => r.id)).toEqual([11, 12]);
    });

    it('keeps abilities when a broadcast echoes a reply back', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        act(() => {
            listeners.get('.message.updated')?.({
                // A broadcast carries no `can`; dropping the HTTP copy's would
                // make the author's own edit controls vanish.
                message: {
                    ...makeMessage(11),
                    body: 'Edited.',
                    can: undefined,
                },
            });
        });

        expect(result.current.replies[0].body).toBe('Edited.');
        expect(result.current.replies[0].can?.update).toBe(true);
    });

    it('counts a new reply into the root tally', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        // The page loaded with one reply already counted by the root.
        expect(result.current.root?.reply_count).toBe(1);

        api.post.mockResolvedValue({
            message: makeMessage(12, { parent_message_id: 10 }),
        });

        await act(async () => {
            await result.current.reply('Snack.');
        });

        /*
         * The panel has to do this itself: ThreadActivity is addressed to the
         * container, so nothing tells the thread its count moved. Without it the
         * divider reads "No replies yet" with a reply sitting under it.
         */
        expect(result.current.root?.reply_count).toBe(2);
    });

    it('does not double-count a reply delivered twice', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        const reply = makeMessage(12, { parent_message_id: 10 });
        api.post.mockResolvedValue({ message: reply });

        await act(async () => {
            await result.current.reply('Snack.');
        });

        // The author's own reply comes back over the socket as well.
        act(() => {
            listeners.get('.message.sent')?.({ message: reply });
        });

        expect(result.current.root?.reply_count).toBe(2);
    });

    it('does not count an edit as a new reply', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        act(() => {
            listeners.get('.message.updated')?.({
                message: makeMessage(11, { body: 'Edited.' }),
            });
        });

        expect(result.current.root?.reply_count).toBe(1);
    });

    it('takes a deleted reply back out of the tally', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        act(() => {
            listeners.get('.message.deleted')?.({
                id: 11,
                channel_id: 7,
                conversation_id: null,
                parent_message_id: 10,
            });
        });

        expect(result.current.root?.reply_count).toBe(0);
    });

    it('removes a reply that was deleted elsewhere', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        act(() => {
            listeners.get('.message.deleted')?.({
                id: 11,
                channel_id: 7,
                conversation_id: null,
                parent_message_id: 10,
            });
        });

        expect(result.current.replies).toHaveLength(0);
    });

    it('does not sync a read pointer for a thread it does not follow', async () => {
        const { result } = mount(
            makePage({
                subscription: {
                    is_subscribed: false,
                    last_read_reply_id: null,
                },
            }),
        );

        await waitFor(() => expect(result.current.loading).toBe(false));

        act(() => result.current.markAsRead());

        // Glancing at a thread is not asking to hear about it forever, so
        // reading one you do not follow writes nothing.
        expect(api.post).not.toHaveBeenCalled();
    });

    it('syncs the read pointer for a followed thread', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        act(() => result.current.markAsRead());

        expect(api.post).toHaveBeenCalledWith(
            expect.stringContaining('/thread/read'),
            { last_read_reply_id: 11 },
        );
    });

    it('rolls the follow toggle back when the server refuses', async () => {
        const { result } = mount();

        await waitFor(() => expect(result.current.loading).toBe(false));

        api.delete.mockRejectedValue(new Error('nope'));

        await act(async () => {
            await result.current.setFollowing(false).catch(() => undefined);
        });

        // The control must not claim a state the server never accepted.
        expect(result.current.subscription.is_subscribed).toBe(true);
    });
});
