import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Typing indicators.
 *
 * The whole feature is client-side — no controller, no queue, no row — so the
 * only place its rules exist is this hook, and every one of them is about
 * failure: a dropped connection, a closed tab, a laptop shut mid-word.
 */
const whispers = vi.hoisted(() => ({
    sent: [] as Array<{ event: string; payload: unknown }>,
    listeners: new Map<string, (payload: never) => void>(),
}));

const channel = vi.hoisted(() => ({
    whisper: vi.fn((event: string, payload: unknown) => {
        whispers.sent.push({ event, payload });
    }),
    listenForWhisper: vi.fn((event: string, callback: (p: never) => void) => {
        whispers.listeners.set(event, callback);
    }),
    stopListeningForWhisper: vi.fn((event: string) => {
        whispers.listeners.delete(event);
    }),
}));

vi.mock('@laravel/echo-react', () => ({
    useChannel: () => ({ channel: () => channel }),
    configureEcho: vi.fn(),
}));

const { useTyping } = await import('@/hooks/use-typing');

function mount() {
    return renderHook(() =>
        useTyping({
            channelName: 'channel.7',
            currentUserId: 1,
            currentUserName: 'Ada',
        }),
    );
}

/** Deliver a whisper as though it arrived over the socket. */
function receive(event: string, payload: unknown) {
    act(() => {
        whispers.listeners.get(event)?.(payload as never);
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    whispers.sent.length = 0;
    whispers.listeners.clear();
    vi.clearAllMocks();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useTyping', () => {
    it('announces typing', () => {
        const { result } = mount();

        act(() => result.current.startTyping());

        expect(whispers.sent).toEqual([
            { event: 'typing', payload: { id: 1, name: 'Ada' } },
        ]);
    });

    it('throttles repeated keystrokes', () => {
        const { result } = mount();

        act(() => {
            result.current.startTyping();
            result.current.startTyping();
            result.current.startTyping();
        });

        // A burst of typing costs one frame, not one per character.
        expect(whispers.sent).toHaveLength(1);
    });

    it('announces again once the throttle window passes', () => {
        const { result } = mount();

        act(() => result.current.startTyping());
        act(() => {
            vi.advanceTimersByTime(2500);
        });
        act(() => result.current.startTyping());

        // Under the TTL, so a continuous typist never flickers.
        expect(whispers.sent).toHaveLength(2);
    });

    it('announces immediately after stopping', () => {
        const { result } = mount();

        act(() => result.current.startTyping());
        act(() => result.current.stopTyping());
        act(() => result.current.startTyping());

        // Sending a message then typing again must not stay silent for the
        // remainder of the throttle window.
        expect(whispers.sent.filter((w) => w.event === 'typing')).toHaveLength(
            2,
        );
    });

    it('shows someone else typing', () => {
        const { result } = mount();

        receive('typing', { id: 2, name: 'Bo' });

        expect(result.current.typists).toEqual([{ id: 2, name: 'Bo' }]);
    });

    it('ignores its own signal echoed from another tab', () => {
        const { result } = mount();

        receive('typing', { id: 1, name: 'Ada' });

        // "You are typing" is not information you need about yourself.
        expect(result.current.typists).toEqual([]);
    });

    it('does not duplicate a repeated signal', () => {
        const { result } = mount();

        receive('typing', { id: 2, name: 'Bo' });
        receive('typing', { id: 2, name: 'Bo' });

        expect(result.current.typists).toHaveLength(1);
    });

    it('clears someone on an explicit stop', () => {
        const { result } = mount();

        receive('typing', { id: 2, name: 'Bo' });
        receive('typing-stopped', { id: 2 });

        expect(result.current.typists).toEqual([]);
    });

    it('expires a signal that is never followed up', () => {
        const { result } = mount();

        receive('typing', { id: 2, name: 'Bo' });

        act(() => {
            vi.advanceTimersByTime(6000);
        });

        /*
         * The case that matters: a closed tab or dropped connection sends no
         * stop, so the indicator has to time itself out rather than pointing at
         * somebody who left.
         */
        expect(result.current.typists).toEqual([]);
    });

    it('keeps a signal alive that is refreshed before expiry', () => {
        const { result } = mount();

        receive('typing', { id: 2, name: 'Bo' });

        act(() => {
            vi.advanceTimersByTime(3000);
        });

        receive('typing', { id: 2, name: 'Bo' });

        act(() => {
            vi.advanceTimersByTime(3000);
        });

        expect(result.current.typists).toHaveLength(1);
    });

    it('stops listening when unmounted', () => {
        const { unmount } = mount();

        unmount();

        expect(channel.stopListeningForWhisper).toHaveBeenCalledWith(
            'typing',
            expect.any(Function),
        );
    });
});
