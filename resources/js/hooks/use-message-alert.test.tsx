import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useMessageAlert } from '@/hooks/use-message-alert';
import type { Message, SnackUser } from '@/types';

const playArrival = vi.hoisted(() => vi.fn());

/*
 * The stand-in returns one stable function, as the real hook does — it memoises
 * on `enabled`. A fresh function per render would silently defeat the stability
 * test below, which is the one assertion here that guards a real bug.
 */
vi.mock('@/hooks/use-notification-sound', () => ({
    useNotificationSound: () => playArrival,
}));

const notifications = vi.hoisted(() => ({
    showNotification: vi.fn(),
    pageIsUnattended: vi.fn(() => true),
}));

vi.mock('@/lib/notifications', () => notifications);

const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({ router: { visit } }));

const ada: SnackUser = {
    id: 1,
    name: 'Ada Lovelace',
    username: 'ada',
    initials: 'AL',
    avatar_url: null,
    title: null,
    department: null,
    presence_status: 'online',
    status_emoji: null,
    status_message: null,
};

function message(overrides: Partial<Message> = {}): Message {
    return {
        id: 10,
        workspace_id: 1,
        channel_id: 1,
        conversation_id: null,
        parent_message_id: null,
        body: 'lunch?',
        type: 'default',
        sender: ada,
        reply_count: 0,
        last_reply_at: null,
        has_attachments: false,
        is_edited: false,
        edited_at: null,
        created_at: null,
        ...overrides,
    } as Message;
}

const options = {
    enabled: true,
    title: (name: string) => `${name} in #general`,
    tag: 'channel:1',
    href: '/w/acme/c/general',
};

beforeEach(() => {
    vi.clearAllMocks();
    notifications.pageIsUnattended.mockReturnValue(true);
});

describe('useMessageAlert', () => {
    it('plays the sound and raises a notification for an unattended page', () => {
        const { result } = renderHook(() => useMessageAlert(options));

        result.current(message());

        expect(playArrival).toHaveBeenCalledOnce();
        expect(notifications.showNotification).toHaveBeenCalledWith(
            expect.objectContaining({
                title: 'Ada Lovelace in #general',
                body: 'lunch?',
                tag: 'channel:1',
            }),
        );
    });

    it('plays the sound but shows no banner while the reader is watching', () => {
        // The message is already on screen. A banner for it is pure noise, and
        // the fastest way to get notifications switched off for good.
        notifications.pageIsUnattended.mockReturnValue(false);

        const { result } = renderHook(() => useMessageAlert(options));

        result.current(message());

        expect(playArrival).toHaveBeenCalledOnce();
        expect(notifications.showNotification).not.toHaveBeenCalled();
    });

    it('stays silent when the container is muted', () => {
        const { result } = renderHook(() =>
            useMessageAlert({ ...options, enabled: false }),
        );

        result.current(message());

        expect(playArrival).not.toHaveBeenCalled();
        expect(notifications.showNotification).not.toHaveBeenCalled();
    });

    it('ignores system messages', () => {
        // "X added Y to the group" has no sender and nobody waiting on it.
        const { result } = renderHook(() => useMessageAlert(options));

        result.current(message({ sender: null, type: 'system' }));

        expect(playArrival).not.toHaveBeenCalled();
        expect(notifications.showNotification).not.toHaveBeenCalled();
    });

    it('navigates to the container when the notification is clicked', () => {
        const { result } = renderHook(() => useMessageAlert(options));

        result.current(message());

        const { onClick } = notifications.showNotification.mock.calls[0][0];
        onClick();

        expect(visit).toHaveBeenCalledWith('/w/acme/c/general');
    });

    it('keeps a stable callback that still sees the current container', () => {
        /*
         * The timeline subscribes with a fixed dependency list, so it holds the
         * first callback it is given forever. If that closure captured the
         * options by value, switching conversations would notify under the
         * previous one's title.
         */
        const { result, rerender } = renderHook(
            (props) => useMessageAlert(props),
            { initialProps: options },
        );

        const first = result.current;

        rerender({ ...options, title: () => 'Second', tag: 'channel:2' });

        expect(result.current).toBe(first);

        first(message());

        expect(notifications.showNotification).toHaveBeenCalledWith(
            expect.objectContaining({ title: 'Second', tag: 'channel:2' }),
        );
    });
});
