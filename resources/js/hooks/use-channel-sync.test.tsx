import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChannelSignalEvent } from '@/types';

/**
 * What keeps a channel's sidebar badge honest.
 *
 * The counts are server-computed — the client cannot reproduce them — so every
 * event here is only a signal that the shared `snack` prop is stale. The two
 * cases that matter are opposites: a message arriving somewhere the reader is
 * not, which has to *raise* a badge, and the reader's own read pointer moving,
 * which has to *clear* one.
 */
const socket = vi.hoisted(() => ({
    handlers: new Map<string, (payload: ChannelSignalEvent) => void>(),
}));

const inertia = vi.hoisted(() => ({ reload: vi.fn(), visit: vi.fn() }));
const sound = vi.hoisted(() => ({ play: vi.fn() }));

const notifications = vi.hoisted(() => ({
    pageIsUnattended: vi.fn(() => false),
    showNotification: vi.fn(),
}));

vi.mock('@laravel/echo-react', () => ({
    useEcho: (
        _channel: string,
        names: string[],
        callback: (payload: ChannelSignalEvent) => void,
    ) => {
        for (const name of names) {
            socket.handlers.set(name, callback);
        }
    },
    configureEcho: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ router: inertia }));
vi.mock('@/lib/notifications', () => notifications);

vi.mock('@/hooks/use-notification-sound', () => ({
    useNotificationSound: () => sound.play,
}));

const active = vi.hoisted(() => ({ slug: null as string | null }));

vi.mock('@/hooks/use-active-channel', () => ({
    useActiveChannelSlug: () => active.slug,
}));

const workspace = vi.hoisted(() => ({ current: null as unknown }));

vi.mock('@/hooks/use-workspace', () => ({
    useCurrentWorkspace: () => workspace.current,
}));

const { useChannelSync } = await import('@/hooks/use-channel-sync');
const { events } = await import('@/lib/echo');

function fire(name: string, payload: ChannelSignalEvent = { channel_id: 7 }) {
    act(() => socket.handlers.get(name)?.(payload));
}

/** Let the refresh debounce elapse. */
function settle() {
    act(() => vi.advanceTimersByTime(400));
}

beforeEach(() => {
    vi.useFakeTimers();
    socket.handlers.clear();
    inertia.reload.mockClear();
    inertia.visit.mockClear();
    sound.play.mockClear();
    notifications.pageIsUnattended.mockReset().mockReturnValue(false);
    notifications.showNotification.mockReset();
    active.slug = null;

    workspace.current = {
        workspace: { slug: 'acme' },
        channels: [
            {
                id: 7,
                name: 'design',
                slug: 'design',
                is_muted: false,
                notification_level: 'all',
            },
        ],
        conversations: [],
    };
});

afterEach(() => vi.useRealTimers());

describe('reading a channel', () => {
    it('re-reads the counts when the read pointer moves', () => {
        renderHook(() => useChannelSync(1));

        fire(events.channelRead);
        settle();

        /*
         * The badge for the channel being read is the whole reason this
         * subscription exists. The count was computed when the page rendered,
         * which is before this client reported what it had displayed — so
         * without this the dot and the mention pill stay lit on a channel the
         * reader is looking at, and only clear on some later navigation.
         */
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['snack'] });
    });

    it('says nothing out loud about it', () => {
        renderHook(() => useChannelSync(1));

        fire(events.channelRead);
        settle();

        // Your own read pointer moving is not news. It reaches this tab because
        // it may have been another tab that did the reading.
        expect(sound.play).not.toHaveBeenCalled();
        expect(notifications.showNotification).not.toHaveBeenCalled();
    });
});

describe('channel membership removal', () => {
    it('leaves a removed channel that is currently open', () => {
        active.slug = 'design';

        renderHook(() => useChannelSync(1));

        fire(events.channelMembershipRevoked);

        expect(inertia.visit).toHaveBeenCalledWith('/w/acme');
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('refreshes the sidebar when another channel removes the user', () => {
        renderHook(() => useChannelSync(1));

        fire(events.channelMembershipRevoked, { channel_id: 8 });
        settle();

        expect(inertia.visit).not.toHaveBeenCalled();
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['snack'] });
    });
});

describe('a message elsewhere', () => {
    it('re-reads the counts and plays the arrival sound', () => {
        renderHook(() => useChannelSync(1));

        fire(events.channelActivity);
        settle();

        expect(inertia.reload).toHaveBeenCalledWith({ only: ['snack'] });
        expect(sound.play).toHaveBeenCalled();
    });

    it('stays quiet for the channel already on screen', () => {
        active.slug = 'design';

        renderHook(() => useChannelSync(1));

        fire(events.channelActivity);
        settle();

        // The open timeline has the message itself and announces it there. Both
        // signals reach this reader, so without the check they get two alerts.
        expect(sound.play).not.toHaveBeenCalled();
        expect(inertia.reload).toHaveBeenCalled();
    });
});
