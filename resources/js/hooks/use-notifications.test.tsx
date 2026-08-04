import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { NotificationEntry } from '@/types';

/**
 * The notification arrival is captured rather than connected, so a test can
 * deliver a broadcast by hand.
 *
 * `useEchoNotification` takes its callback as the *second* argument and its
 * dependency list as the fourth — the third is the event name. Passing the deps
 * in the third position subscribes once and never re-subscribes, which in the
 * browser looks exactly like the socket dying, so the mock records the argument
 * positions the hook is expected to use.
 */
const arrivals = vi.hoisted(
    () => ({ callback: null }) as { callback: ((p: unknown) => void) | null },
);

vi.mock('@laravel/echo-react', () => ({
    useEchoNotification: (
        _channel: string,
        callback: (payload: unknown) => void,
    ) => {
        arrivals.callback = callback;

        return { leave: vi.fn(), leaveChannel: vi.fn() };
    },
    configureEcho: vi.fn(),
}));

const router = vi.hoisted(() => ({ reload: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router }));

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

const { useNotifications } = await import('@/hooks/use-notifications');

function makeNotification(
    id: string,
    overrides: Partial<NotificationEntry> = {},
): NotificationEntry {
    return {
        id,
        type: 'mention',
        is_read: false,
        read_at: null,
        created_at: '2026-07-31T09:00:00+00:00',
        count: 1,
        message_id: 1,
        thread_root_id: null,
        preview: 'take a look',
        actor: {
            id: 2,
            name: 'Alicia',
            username: 'alicia',
            initials: 'A',
            avatar_url: null,
        },
        container: {
            kind: 'channel',
            id: 1,
            name: 'design',
            slug: 'design',
            type: 'public',
        },
        ...overrides,
    };
}

function render(initial: NotificationEntry[]) {
    return renderHook(
        ({ list }: { list: NotificationEntry[] }) =>
            useNotifications({
                workspaceSlug: 'snack-hq',
                userId: 1,
                initial: list,
            }),
        { initialProps: { list: initial } },
    );
}

beforeEach(() => {
    arrivals.callback = null;
    router.reload.mockClear();
    api.post.mockReset();
    api.post.mockResolvedValue({ ok: true });
});

describe('useNotifications', () => {
    it('prepends an arriving notification', () => {
        const { result } = render([makeNotification('a')]);

        act(() => arrivals.callback?.(makeNotification('b')));

        expect(result.current.notifications.map((n) => n.id)).toEqual([
            'b',
            'a',
        ]);
    });

    it('does not duplicate an arrival it has already rendered', () => {
        const { result } = render([makeNotification('a')]);

        // The same notification can be delivered twice — a reconnect replays
        // what the socket missed, and a duplicated row reads as two mentions.
        act(() => arrivals.callback?.(makeNotification('a')));

        expect(result.current.notifications).toHaveLength(1);
    });

    it('replaces a row the server has folded another message into', () => {
        const { result } = render([
            makeNotification('b'),
            makeNotification('a', { type: 'direct_message' }),
        ]);

        /*
         * NotificationCoalescer rewrites the unread row for a conversation and
         * re-broadcasts it under the same id, so this arrival is an update
         * rather than a duplicate. Keeping the copy already on screen would
         * leave the reader looking at the first message of a conversation that
         * has since moved on.
         */
        act(() =>
            arrivals.callback?.(
                makeNotification('a', {
                    type: 'direct_message',
                    count: 3,
                    preview: 'and one more thing',
                }),
            ),
        );

        expect(result.current.notifications).toHaveLength(2);
        expect(result.current.notifications[0]).toMatchObject({
            id: 'a',
            count: 3,
            preview: 'and one more thing',
        });

        // Hoisted to the top, which is what the server did to created_at.
        expect(result.current.notifications.map((n) => n.id)).toEqual([
            'a',
            'b',
        ]);
    });

    it('asks the server for a fresh badge rather than counting locally', () => {
        render([]);

        act(() => arrivals.callback?.(makeNotification('a')));

        // The count lives in the shared `snack` prop. Incrementing a local copy
        // would give the app two answers to one question.
        expect(router.reload).toHaveBeenCalledWith({ only: ['snack'] });
    });

    it('re-seeds from a new server response', () => {
        const { result, rerender } = render([makeNotification('a')]);

        /*
         * What "Mark all read" does: it redirects back, and the server returns
         * every row stamped read. Without the re-seed the list stays frozen at
         * its first render, so the badge clears while the rows stay bold.
         */
        rerender({ list: [makeNotification('a', { is_read: true })] });

        expect(result.current.notifications[0].is_read).toBe(true);
    });

    it('keeps a live arrival when only the badge is refreshed', () => {
        const initial = [makeNotification('a')];
        const { result, rerender } = render(initial);

        act(() => arrivals.callback?.(makeNotification('b')));

        // A partial reload leaves this prop's identity untouched, so re-rendering
        // with the same array must not throw the arrival away.
        rerender({ list: initial });

        expect(result.current.notifications.map((n) => n.id)).toEqual([
            'b',
            'a',
        ]);
    });

    it('marks a notification read optimistically', async () => {
        const { result } = render([makeNotification('a')]);

        await act(() =>
            result.current.markAsRead(result.current.notifications[0]),
        );

        await waitFor(() =>
            expect(result.current.notifications[0].is_read).toBe(true),
        );

        expect(api.post).toHaveBeenCalledOnce();
    });

    it('rolls the row back when the server rejects it', async () => {
        api.post.mockRejectedValue(new Error('nope'));

        const { result } = render([makeNotification('a')]);

        await act(async () => {
            await result.current
                .markAsRead(result.current.notifications[0])
                .catch(() => {});
        });

        expect(result.current.notifications[0].is_read).toBe(false);
    });

    it('does not re-stamp a notification that is already read', async () => {
        const { result } = render([makeNotification('a', { is_read: true })]);

        await act(() =>
            result.current.markAsRead(result.current.notifications[0]),
        );

        // Opening something twice should not move when it was first seen.
        expect(api.post).not.toHaveBeenCalled();
    });
});
