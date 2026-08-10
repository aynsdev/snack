import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    useIsActivityView,
    useIsCanvasesView,
    useIsDirectMessagesView,
    useIsHomeView,
} from '@/hooks/use-active-channel';

const currentUrl = vi.hoisted(() => ({ value: '/' }));

vi.mock('@/hooks/use-current-url', () => ({
    useCurrentUrl: () => ({ currentUrl: currentUrl.value }),
}));

/**
 * Which navigation rail item is lit is decided by matching the URL, so these are
 * tests of the boundaries: a section must claim its own pages and nothing that
 * merely starts with the same letters.
 */
function at(url: string, hook: () => boolean): boolean {
    currentUrl.value = url;

    return renderHook(hook).result.current;
}

beforeEach(() => {
    currentUrl.value = '/';
});

describe('useIsHomeView', () => {
    it('claims the workspace root and its channels', () => {
        expect(at('/w/snack-hq', useIsHomeView)).toBe(true);
        expect(at('/w/snack-hq/', useIsHomeView)).toBe(true);
        expect(at('/w/snack-hq/channels', useIsHomeView)).toBe(true);
        expect(at('/w/snack-hq/channels/general', useIsHomeView)).toBe(true);
    });

    it('claims nothing else in the workspace', () => {
        expect(at('/w/snack-hq/dms', useIsHomeView)).toBe(false);
        expect(at('/w/snack-hq/activity', useIsHomeView)).toBe(false);
        expect(at('/w/snack-hq/files', useIsHomeView)).toBe(false);
    });
});

describe('useIsDirectMessagesView', () => {
    it('covers the DM home and the conversations inside it', () => {
        expect(at('/w/snack-hq/dms', useIsDirectMessagesView)).toBe(true);
        expect(at('/w/snack-hq/dms/01HZX', useIsDirectMessagesView)).toBe(true);
    });

    it('does not spill onto a path that merely starts the same', () => {
        // The sidebar changes shape on this answer, so a false positive would
        // replace the channel list on a page that is not a DM at all.
        expect(at('/w/snack-hq/dmsomething', useIsDirectMessagesView)).toBe(
            false,
        );
    });
});

describe('useIsActivityView', () => {
    it('covers the tabbed page and the three views it gathers', () => {
        expect(at('/w/snack-hq/activity', useIsActivityView)).toBe(true);
        expect(at('/w/snack-hq/activity?tab=mentions', useIsActivityView)).toBe(
            true,
        );
        expect(at('/w/snack-hq/notifications', useIsActivityView)).toBe(true);
        expect(at('/w/snack-hq/mentions', useIsActivityView)).toBe(true);
        expect(at('/w/snack-hq/threads', useIsActivityView)).toBe(true);
    });

    it('leaves notification preferences alone', () => {
        // A different page on a URL that shares no path segment with the inbox.
        expect(
            at('/w/snack-hq/notification-preferences', useIsActivityView),
        ).toBe(false);
    });
});

describe('useIsCanvasesView', () => {
    it('covers the canvas browser and documents inside it', () => {
        expect(at('/w/snack-hq/canvases', useIsCanvasesView)).toBe(true);
        expect(at('/w/snack-hq/canvases/01K2CANVAS', useIsCanvasesView)).toBe(
            true,
        );
    });

    it('does not spill onto a path that merely starts the same', () => {
        expect(at('/w/snack-hq/canvases-old', useIsCanvasesView)).toBe(false);
    });
});
