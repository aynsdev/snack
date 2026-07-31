import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Channel, Message, MessagePage, SnackUser } from '@/types';

/**
 * Echo subscriptions are captured rather than connected, so a test can deliver
 * a broadcast by hand and assert how the timeline reconciles it.
 */
const listeners = vi.hoisted(
    () => new Map<string, (payload: unknown) => void>(),
);

vi.mock('@laravel/echo-react', () => ({
    useEchoPresence: (
        _channel: string,
        event: string,
        callback: (payload: unknown) => void,
    ) => {
        listeners.set(event, callback);

        return { leave: vi.fn(), leaveChannel: vi.fn() };
    },
    // lib/echo.ts calls this at module load to configure the transport; it has
    // to exist on the mock even though nothing here connects.
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

const { useChannelMessages } = await import('@/hooks/use-channel-messages');

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

const channel = {
    id: 7,
    slug: 'general',
    is_member: true,
    is_muted: false,
} as Channel;

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
        is_edited: false,
        edited_at: null,
        created_at: '2026-07-29T10:00:00Z',
        can: { update: true, delete: true, pin: true },
        ...overrides,
    };
}

function setup(initial: Partial<MessagePage> = {}) {
    return renderHook(() =>
        useChannelMessages({
            channel,
            workspaceSlug: 'snack-hq',
            initial: {
                messages: [makeMessage(1)],
                has_more: false,
                next_cursor: null,
                ...initial,
            },
            currentUser,
        }),
    );
}

beforeEach(() => {
    listeners.clear();
    vi.clearAllMocks();
});

describe('useChannelMessages', () => {
    it('starts from the server-rendered page', () => {
        const { result } = setup();

        expect(result.current.messages.map((m) => m.id)).toEqual([1]);
    });

    it('appends a broadcast message', () => {
        const { result } = setup();

        act(() => {
            listeners.get('.message.sent')?.({ message: makeMessage(2) });
        });

        expect(result.current.messages.map((m) => m.id)).toEqual([1, 2]);
    });

    it('keeps the list ordered by id when a broadcast arrives out of order', () => {
        const { result } = setup({
            messages: [makeMessage(1), makeMessage(3)],
        });

        act(() => {
            listeners.get('.message.sent')?.({ message: makeMessage(2) });
        });

        // Ordering by id, not arrival: two app servers can stamp timestamps
        // that disagree, but ids come from one authority.
        expect(result.current.messages.map((m) => m.id)).toEqual([1, 2, 3]);
    });

    it('does not duplicate a message that arrives twice', () => {
        const { result } = setup();

        act(() => {
            listeners.get('.message.sent')?.({ message: makeMessage(2) });
            listeners.get('.message.sent')?.({ message: makeMessage(2) });
        });

        expect(result.current.messages).toHaveLength(2);
    });

    it('shows a sent message immediately, before the server replies', async () => {
        api.post.mockImplementation(() => new Promise(() => {}));

        const { result } = setup();

        act(() => {
            void result.current.send('optimistic');
        });

        expect(result.current.pending).toHaveLength(1);
        expect(result.current.pending[0].body).toBe('optimistic');
        expect(result.current.pending[0].failed).toBe(false);
    });

    it('replaces the optimistic copy with the confirmed message', async () => {
        api.post.mockResolvedValue({
            message: makeMessage(2, { body: 'sent' }),
        });

        const { result } = setup();

        await act(async () => {
            await result.current.send('sent');
        });

        expect(result.current.pending).toHaveLength(0);
        expect(result.current.messages.map((m) => m.id)).toEqual([1, 2]);
    });

    it('does not duplicate when the broadcast beats the http response', async () => {
        const confirmed = makeMessage(2, { body: 'raced' });

        api.post.mockImplementation(
            () =>
                new Promise((resolve) => {
                    // The author is not excluded from their own broadcast —
                    // their other devices need it — so both paths deliver the
                    // same message and either can land first.
                    listeners.get('.message.sent')?.({ message: confirmed });
                    resolve({ message: confirmed });
                }),
        );

        const { result } = setup();

        await act(async () => {
            await result.current.send('raced');
        });

        expect(result.current.messages.filter((m) => m.id === 2)).toHaveLength(
            1,
        );
        expect(result.current.pending).toHaveLength(0);
    });

    it('keeps a failed message on screen so it can be retried', async () => {
        api.post.mockRejectedValue(new Error('offline'));

        const { result } = setup();

        await act(async () => {
            await result.current.send('never arrives');
        });

        // Dropping it silently is the worst outcome: the author watched it
        // appear and has already moved on.
        expect(result.current.pending).toHaveLength(1);
        expect(result.current.pending[0].failed).toBe(true);
        expect(result.current.messages).toHaveLength(1);
    });

    it('discards a failed message on request', async () => {
        api.post.mockRejectedValue(new Error('offline'));

        const { result } = setup();

        await act(async () => {
            await result.current.send('give up');
        });

        act(() => result.current.discard(result.current.pending[0].nonce));

        expect(result.current.pending).toHaveLength(0);
    });

    it('resends a failed message on retry', async () => {
        api.post.mockRejectedValueOnce(new Error('offline'));

        const { result } = setup();

        await act(async () => {
            await result.current.send('try again');
        });

        api.post.mockResolvedValue({
            message: makeMessage(2, { body: 'try again' }),
        });

        await act(async () => {
            result.current.retry(result.current.pending[0].nonce);
        });

        await waitFor(() => expect(result.current.pending).toHaveLength(0));
        expect(result.current.messages.map((m) => m.id)).toEqual([1, 2]);
    });

    it('prepends older history without disturbing what is loaded', async () => {
        api.get.mockResolvedValue({
            messages: [makeMessage(-1), makeMessage(0)],
            has_more: false,
            next_cursor: null,
        });

        const { result } = setup({ has_more: true, next_cursor: 1 });

        await act(async () => {
            await result.current.loadOlder();
        });

        expect(result.current.messages.map((m) => m.id)).toEqual([-1, 0, 1]);
        expect(result.current.hasMore).toBe(false);
    });

    it('does not request older history when there is none', async () => {
        const { result } = setup({ has_more: false, next_cursor: null });

        await act(async () => {
            await result.current.loadOlder();
        });

        expect(api.get).not.toHaveBeenCalled();
    });

    it('drops a message that was deleted elsewhere', () => {
        const { result } = setup({
            messages: [makeMessage(1), makeMessage(2)],
        });

        act(() => {
            listeners.get('.message.deleted')?.({
                id: 2,
                channel_id: 7,
                conversation_id: null,
                parent_message_id: null,
            });
        });

        expect(result.current.messages.map((m) => m.id)).toEqual([1]);
    });

    it('applies an edit broadcast in place', () => {
        const { result } = setup();

        act(() => {
            listeners.get('.message.updated')?.({
                message: makeMessage(1, { body: 'corrected', is_edited: true }),
            });
        });

        expect(result.current.messages[0].body).toBe('corrected');
        expect(result.current.messages[0].is_edited).toBe(true);
    });

    it('keeps the viewer permissions when a broadcast replaces a message', () => {
        const { result } = setup();

        act(() => {
            // A broadcast reaches every subscriber at once and so cannot carry
            // per-viewer permissions; dropping them here would make the
            // author's own edit controls vanish as their message echoes back.
            const broadcast = makeMessage(1, { body: 'edited' });
            delete broadcast.can;

            listeners.get('.message.updated')?.({ message: broadcast });
        });

        expect(result.current.messages[0].can).toEqual({
            update: true,
            delete: true,
            pin: true,
        });
    });

    it('keeps the private save flag when a broadcast replaces a message', async () => {
        const { result } = setup({
            messages: [makeMessage(1, { is_saved: true })],
        });

        act(() => {
            /*
             * A save is private, so a broadcast has no single answer to carry
             * and omits it. Taking the incoming `undefined` would silently
             * un-bookmark a message the viewer had saved, the moment anybody
             * edited it.
             */
            const broadcast = makeMessage(1, { body: 'edited' });
            delete broadcast.is_saved;

            listeners.get('.message.updated')?.({ message: broadcast });
        });

        expect(result.current.messages[0].is_saved).toBe(true);
        expect(result.current.messages[0].body).toBe('edited');
    });

    it('restores a message when the server refuses to delete it', async () => {
        api.delete.mockRejectedValue(new Error('forbidden'));

        const { result } = setup();

        await act(async () => {
            await expect(result.current.remove(1)).rejects.toThrow();
        });

        // A message that looks deleted but is not is a message the author
        // believes they retracted.
        expect(result.current.messages.map((m) => m.id)).toEqual([1]);
    });

    it('rolls an edit back when the server rejects it', async () => {
        api.patch.mockRejectedValue(new Error('forbidden'));

        const { result } = setup();

        await act(async () => {
            await expect(result.current.edit(1, 'rewritten')).rejects.toThrow();
        });

        expect(result.current.messages[0].body).toBe('message 1');
    });
});
