import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The "Bite is replying…" signal.
 *
 * Unlike typing, this arrives from the server — Bite is a queue worker with no
 * socket of its own. Every rule here is about the ending going missing: a worker
 * killed mid-answer never sends one, so the indicator has to take itself down.
 */
const socket = vi.hoisted(() => ({
    subscriptions: [] as Array<{ channel: string; visibility?: string }>,
    deliver: null as null | ((payload: unknown) => void),
}));

vi.mock('@laravel/echo-react', () => ({
    useEcho: (
        channelName: string,
        _event: string,
        callback: (payload: never) => void,
        _deps: unknown[],
        visibility?: string,
    ) => {
        socket.subscriptions.push({ channel: channelName, visibility });
        socket.deliver = callback as (payload: unknown) => void;

        return { channel: () => null };
    },
    configureEcho: vi.fn(),
}));

const { useBiteReplying } = await import('@/hooks/use-bite-replying');

function mount(visibility?: 'presence' | 'private') {
    return renderHook(() =>
        useBiteReplying({ channelName: 'channel.7', visibility }),
    );
}

/** Deliver a broadcast as though it arrived over the socket. */
function receive(payload: {
    replying: boolean;
    name?: string;
    expires_in?: number;
}) {
    act(() => {
        socket.deliver?.({
            id: 28,
            name: payload.name ?? 'Bite',
            replying: payload.replying,
            expires_in: payload.expires_in ?? 60,
        });
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    socket.subscriptions.length = 0;
    socket.deliver = null;
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useBiteReplying', () => {
    it('is quiet until something arrives', () => {
        const { result } = mount();

        expect(result.current).toBeNull();
    });

    it('names the assistant while it is working', () => {
        const { result } = mount();

        receive({ replying: true });

        expect(result.current).toBe('Bite');
    });

    it('goes quiet when the work finishes', () => {
        const { result } = mount();

        receive({ replying: true });
        receive({ replying: false });

        expect(result.current).toBeNull();
    });

    /**
     * The case the explicit ending cannot cover: the worker died, was deployed
     * over, or hung past its own timeout. An indicator with no expiry would
     * leave an assistant that is forever about to speak.
     */
    it('expires on its own when no ending arrives', () => {
        const { result } = mount();

        receive({ replying: true, expires_in: 60 });

        act(() => void vi.advanceTimersByTime(59_000));
        expect(result.current).toBe('Bite');

        act(() => void vi.advanceTimersByTime(1_000));
        expect(result.current).toBeNull();
    });

    /**
     * A second answer starting before the first one's lifetime runs out must
     * re-arm the clock rather than inherit the old deadline — otherwise the
     * indicator vanishes partway through the new answer.
     */
    it('re-arms the expiry when another answer starts', () => {
        const { result } = mount();

        receive({ replying: true, expires_in: 60 });
        act(() => void vi.advanceTimersByTime(50_000));

        receive({ replying: true, expires_in: 60 });
        act(() => void vi.advanceTimersByTime(20_000));

        expect(result.current).toBe('Bite');
    });

    it('subscribes to containers as presence and threads as private', () => {
        mount();
        mount('private');

        expect(socket.subscriptions[0].visibility).toBe('presence');
        expect(socket.subscriptions.at(-1)?.visibility).toBe('private');
    });

    it('drops a pending expiry when the panel closes', () => {
        const { result, unmount } = mount();

        receive({ replying: true, expires_in: 60 });
        unmount();

        // Firing after unmount would set state on a component that is gone.
        expect(() =>
            act(() => void vi.advanceTimersByTime(60_000)),
        ).not.toThrow();

        expect(result.current).toBe('Bite');
    });
});
