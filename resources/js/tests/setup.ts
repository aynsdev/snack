import '@testing-library/jest-dom/vitest';

import { notifyManager } from '@tanstack/react-query';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

/**
 * Flush query-cache notifications synchronously.
 *
 * Message timelines live in the TanStack Query cache, and a broadcast or an
 * optimistic send lands there via `setQueryData`. In the browser the resulting
 * re-render is batched onto a scheduler, which is right for a busy channel and
 * wrong for a test: the write happens inside `act`, the notification arrives
 * after it, and the assertion reads the render before the change.
 *
 * Making the scheduler synchronous keeps the tests asserting what they always
 * asserted — what the timeline holds after an event — rather than each of them
 * growing a `waitFor` around the framework's batching.
 */
notifyManager.setScheduler((callback) => callback());

afterEach(() => {
    cleanup();
});

/**
 * jsdom does not implement `matchMedia`, which the appearance (light/dark) hook
 * and the responsive layout hooks both rely on.
 */
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }),
});

/**
 * Virtualized message lists measure their scroll container; jsdom reports zero
 * for every box, so provide a deterministic observer stub.
 */
class ResizeObserverStub {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
}

Object.defineProperty(window, 'ResizeObserver', {
    writable: true,
    value: ResizeObserverStub,
});

Object.defineProperty(window, 'scrollTo', { writable: true, value: vi.fn() });
