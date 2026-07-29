import '@testing-library/jest-dom/vitest';

import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

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
