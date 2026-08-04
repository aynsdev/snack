import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import type { Huddle } from '@/types';

/**
 * The huddle a container is currently showing.
 *
 * Two rules, both about state that is wrong in a way nothing errors on: a
 * finished huddle that never leaves the bar, and one channel's huddle following
 * the reader into the next channel.
 */
const socket = vi.hoisted(() => ({
    listeners: new Map<string, (payload: never) => void>(),
}));

vi.mock('@laravel/echo-react', () => ({
    useEchoPresence: (
        channelName: string,
        event: string,
        callback: (payload: never) => void,
    ) => {
        socket.listeners.set(`${channelName}:${event}`, callback);
    },
    configureEcho: vi.fn(),
}));

const { useContainerHuddle } = await import('@/hooks/use-container-huddle');

/** What an Inertia visit hands the hook. */
type PageProps = { containerKey: string; initial: Huddle | null };

function huddle(overrides: Partial<Huddle> = {}): Huddle {
    return {
        id: 'huddle-1',
        container: { kind: 'channel', id: 7 },
        started_at: '2026-08-03T10:00:00+08:00',
        ended_at: null,
        is_live: true,
        participants: [],
        ...overrides,
    };
}

/** Deliver a `huddle.changed` frame as though it arrived over the socket. */
function receive(channelName: string, payload: { huddle: Huddle | null }) {
    act(() => {
        socket.listeners.get(`${channelName}:.huddle.changed`)?.(
            payload as never,
        );
    });
}

beforeEach(() => {
    socket.listeners.clear();
});

it('starts from what the page was rendered with', () => {
    const { result } = renderHook(() =>
        useContainerHuddle({
            broadcastChannel: 'channel.7',
            containerKey: 'channel:7',
            initial: huddle(),
        }),
    );

    expect(result.current?.id).toBe('huddle-1');
});

it('replaces the whole huddle on every frame rather than merging', () => {
    const { result } = renderHook(() =>
        useContainerHuddle({
            broadcastChannel: 'channel.7',
            containerKey: 'channel:7',
            initial: huddle(),
        }),
    );

    receive('channel.7', {
        huddle: huddle({ id: 'huddle-2', participants: [] }),
    });

    expect(result.current?.id).toBe('huddle-2');
});

/**
 * The bar has nothing to say about a call that is over, and holding the row
 * would make every consumer check the flag.
 */
it('drops a huddle that has ended', () => {
    const { result } = renderHook(() =>
        useContainerHuddle({
            broadcastChannel: 'channel.7',
            containerKey: 'channel:7',
            initial: huddle(),
        }),
    );

    receive('channel.7', { huddle: huddle({ is_live: false }) });

    expect(result.current).toBeNull();
});

it('drops the huddle when the container goes away', () => {
    const { result } = renderHook(() =>
        useContainerHuddle({
            broadcastChannel: 'channel.7',
            containerKey: 'channel:7',
            initial: huddle(),
        }),
    );

    receive('channel.7', { huddle: null });

    expect(result.current).toBeNull();
});

/**
 * The trap this hook exists for: `useState(prop)` keeps its first argument
 * forever, so without the re-seed the first channel's huddle would still be on
 * screen after navigating to a second one that has no call at all.
 */
it('re-seeds when the page moves to a different container', () => {
    // Widened explicitly: inferring from the first render would fix `initial`
    // as non-null and refuse the second channel, which has no huddle at all —
    // the very case being asserted.
    const initialProps: PageProps = {
        containerKey: 'channel:7',
        initial: huddle(),
    };

    const { result, rerender } = renderHook(
        (props: PageProps) =>
            useContainerHuddle({
                broadcastChannel: 'channel.7',
                ...props,
            }),
        { initialProps },
    );

    expect(result.current?.id).toBe('huddle-1');

    rerender({ containerKey: 'channel:9', initial: null });

    expect(result.current).toBeNull();
});

/**
 * Inertia hands over a freshly parsed object on every visit, so a re-seed keyed
 * on the prop's identity would undo any broadcast that had arrived since.
 */
it('does not undo a broadcast when the same page merely re-renders', () => {
    const initialProps: PageProps = {
        containerKey: 'channel:7',
        initial: huddle(),
    };

    const { result, rerender } = renderHook(
        (props: PageProps) =>
            useContainerHuddle({
                broadcastChannel: 'channel.7',
                ...props,
            }),
        { initialProps },
    );

    receive('channel.7', { huddle: huddle({ id: 'huddle-2' }) });

    // A new object with the same contents — what a re-render hands over.
    rerender({ containerKey: 'channel:7', initial: huddle() });

    expect(result.current?.id).toBe('huddle-2');
});
