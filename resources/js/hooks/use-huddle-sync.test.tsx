import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { HuddleActivityEvent } from '@/types';

/**
 * Who hears about a call starting, and through which surface.
 *
 * The split this pins is the whole point of the hook: an attended tab gets an
 * in-app toast, an unattended one gets an OS banner, and the sound plays for
 * both. {@see useChannelSync} deliberately does neither unless the page is
 * unattended, because a message can wait — a call cannot, which is why this
 * exists separately rather than as another branch there.
 */
const socket = vi.hoisted(() => ({
    callback: null as ((payload: HuddleActivityEvent) => void) | null,
}));

type ToastOptions = {
    description?: string;
    duration?: number;
    action?: { label: string; onClick: () => void };
};

const toasts = vi.hoisted(() => {
    const toast = vi.fn((_headline: string, _options?: ToastOptions) => {
        void _headline;
        void _options;

        return 'toast-1';
    });

    return Object.assign(toast, { dismiss: vi.fn() });
});

type BannerOptions = {
    title: string;
    body?: string;
    tag: string;
    requireInteraction?: boolean;
    onClick?: () => void;
};

const notifications = vi.hoisted(() => ({
    pageIsUnattended: vi.fn(() => false),
    showNotification: vi.fn((_options: BannerOptions) => {
        void _options;

        return { close: vi.fn() };
    }),
}));

const sound = vi.hoisted(() => ({ play: vi.fn() }));
const huddle = vi.hoisted(() => ({
    join: vi.fn(),
    active: null as { container: { kind: string; id: number | string } } | null,
}));
const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@laravel/echo-react', () => ({
    useEcho: (
        _channel: string,
        _events: string[],
        callback: (payload: HuddleActivityEvent) => void,
    ) => {
        socket.callback = callback;
    },
    configureEcho: vi.fn(),
}));

vi.mock('sonner', () => ({ toast: toasts }));
vi.mock('@/lib/notifications', () => notifications);
vi.mock('@inertiajs/react', () => ({ router: inertia }));

vi.mock('@/hooks/use-notification-sound', () => ({
    useNotificationSound: () => sound.play,
}));

vi.mock('@/hooks/use-huddle', () => ({
    useHuddle: () => ({ join: huddle.join, active: huddle.active }),
}));

const workspace = vi.hoisted(() => ({
    current: null as unknown,
}));

vi.mock('@/hooks/use-workspace', () => ({
    useCurrentWorkspace: () => workspace.current,
}));

const { useHuddleSync } = await import('@/hooks/use-huddle-sync');

function channel(overrides: Record<string, unknown> = {}) {
    return {
        id: 7,
        name: 'design',
        slug: 'design',
        is_muted: false,
        notification_level: 'all',
        ...overrides,
    };
}

function started(
    overrides: Partial<HuddleActivityEvent> = {},
): HuddleActivityEvent {
    return {
        huddle_id: 'huddle-1',
        container: { kind: 'channel', id: 7 },
        is_live: true,
        ...overrides,
    };
}

function fire(payload: HuddleActivityEvent) {
    act(() => socket.callback?.(payload));
}

beforeEach(() => {
    socket.callback = null;
    toasts.mockClear();
    toasts.dismiss.mockClear();
    notifications.pageIsUnattended.mockReset().mockReturnValue(false);
    notifications.showNotification
        .mockReset()
        .mockReturnValue({ close: vi.fn() });
    sound.play.mockClear();
    huddle.join.mockClear();
    huddle.active = null;
    inertia.visit.mockClear();

    workspace.current = {
        workspace: { slug: 'acme' },
        channels: [channel()],
        conversations: [],
    };
});

describe('an attended tab', () => {
    it('raises a toast that can join, and no OS banner', () => {
        renderHook(() => useHuddleSync(1));

        fire(started());

        expect(sound.play).toHaveBeenCalled();

        // The case the message notifiers have no answer for: the reader is
        // right here, and a sidebar dot is not an invitation to a call.
        expect(toasts).toHaveBeenCalledWith(
            '#design',
            expect.objectContaining({ duration: Infinity }),
        );

        // An OS banner for something already on screen is how people learn to
        // switch notifications off.
        expect(notifications.showNotification).not.toHaveBeenCalled();
    });

    it('joins from the toast without navigating first', () => {
        renderHook(() => useHuddleSync(1));

        fire(started());

        const options = toasts.mock.calls[0][1];

        act(() => options?.action?.onClick());

        // The whole decision is "join or don't". Making the user navigate there
        // adds a step to something that is time-limited by nature.
        expect(huddle.join).toHaveBeenCalledWith(
            expect.objectContaining({
                kind: 'channel',
                workspace: 'acme',
                channel: 'design',
                label: '#design',
            }),
        );
    });
});

describe('an unattended tab', () => {
    beforeEach(() => notifications.pageIsUnattended.mockReturnValue(true));

    it('raises an OS banner that persists and only navigates', () => {
        renderHook(() => useHuddleSync(1));

        fire(started());

        expect(sound.play).toHaveBeenCalled();
        expect(notifications.showNotification).toHaveBeenCalledWith(
            expect.objectContaining({
                title: '#design',
                tag: 'huddle:huddle-1',
                // A banner that fades after five seconds is a missed call for
                // exactly the person it was raised for.
                requireInteraction: true,
            }),
        );

        const { onClick } = notifications.showNotification.mock.calls[0][0];

        act(() => onClick?.());

        /*
         * Navigates rather than joining. An OS notification is clicked from
         * muscle memory at least as often as from intent, and the cost of
         * getting that wrong is a live microphone in a room nobody meant to
         * walk into.
         */
        expect(inertia.visit).toHaveBeenCalled();
        expect(huddle.join).not.toHaveBeenCalled();
    });
});

describe('withdrawal', () => {
    it('takes both surfaces down when the huddle ends', () => {
        const close = vi.fn();
        notifications.pageIsUnattended.mockReturnValue(true);
        notifications.showNotification.mockReturnValue({ close });

        renderHook(() => useHuddleSync(1));

        fire(started());
        fire(started({ is_live: false }));

        /*
         * An invitation to a call that finished ten minutes ago is worse than
         * no invitation: it is a promise the app cannot keep, and whoever acts
         * on it finds an empty room and no explanation.
         */
        expect(toasts.dismiss).toHaveBeenCalledWith('toast-1');
        expect(close).toHaveBeenCalled();
    });

    it('ignores an end for a huddle it never announced', () => {
        renderHook(() => useHuddleSync(1));

        fire(started({ huddle_id: 'other', is_live: false }));

        expect(toasts.dismiss).not.toHaveBeenCalled();
    });
});

describe('staying quiet', () => {
    it('says nothing twice for one call', () => {
        renderHook(() => useHuddleSync(1));

        fire(started());
        // A reconnect replays what the socket missed; a second banner for one
        // call reads as a second call.
        fire(started());

        expect(toasts).toHaveBeenCalledTimes(1);
    });

    it('says nothing about a muted channel', () => {
        workspace.current = {
            workspace: { slug: 'acme' },
            channels: [channel({ is_muted: true })],
            conversations: [],
        };

        renderHook(() => useHuddleSync(1));

        fire(started());

        expect(toasts).not.toHaveBeenCalled();
        expect(sound.play).not.toHaveBeenCalled();
    });

    it('says nothing about a channel set to nothing', () => {
        workspace.current = {
            workspace: { slug: 'acme' },
            channels: [channel({ notification_level: 'nothing' })],
            conversations: [],
        };

        renderHook(() => useHuddleSync(1));

        fire(started());

        expect(toasts).not.toHaveBeenCalled();
    });

    it('says nothing about a container it has not been told about', () => {
        renderHook(() => useHuddleSync(1));

        // The sidebar payload has not caught up yet. Naming it wrongly would be
        // worse than saying nothing.
        fire(started({ container: { kind: 'channel', id: 999 } }));

        expect(toasts).not.toHaveBeenCalled();
    });

    it('says nothing about the call this tab is already on', () => {
        huddle.active = { container: { kind: 'channel', id: 7 } };

        renderHook(() => useHuddleSync(1));

        fire(started());

        // What your *other* tab sees when you join from one of them — the server
        // only leaves out the person who started it. Alerting here rings the
        // phone already at your ear.
        expect(toasts).not.toHaveBeenCalled();
    });
});
