import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SidebarSection } from '@/hooks/use-sidebar-section';
import { enterSection, useSidebarSection } from '@/hooks/use-sidebar-section';

const currentUrl = vi.hoisted(() => ({ value: '/' }));

vi.mock('@/hooks/use-current-url', () => ({
    useCurrentUrl: () => ({ currentUrl: currentUrl.value }),
}));

/**
 * One navigation: the URL changes and the shell renders again, which is exactly
 * what a fresh mount reproduces here since the section outlives the components
 * reading it.
 */
function visit(url: string): SidebarSection {
    currentUrl.value = url;

    return renderHook(() => useSidebarSection()).result.current;
}

beforeEach(() => {
    sessionStorage.clear();
    currentUrl.value = '/';
});

describe('useSidebarSection', () => {
    it('is the workspace anywhere outside direct messages', () => {
        expect(visit('/w/snack-hq/channels/general')).toBe('workspace');
        expect(visit('/w/snack-hq/activity')).toBe('workspace');
        expect(visit('/w/snack-hq/files')).toBe('workspace');
    });

    it('is direct messages on the DM home the rail points at', () => {
        expect(visit('/w/snack-hq/dms')).toBe('direct-messages');
    });

    it('keeps the workspace when a DM is opened from the channel sidebar', () => {
        // The bug this hook exists for: the roster sits at the bottom of the
        // workspace sidebar, so opening a name from it used to replace that
        // whole sidebar — channels and all — with the DM list.
        visit('/w/snack-hq/channels/general');

        expect(visit('/w/snack-hq/dms/01HZXCONVERSATION')).toBe('workspace');
    });

    it('keeps direct messages when a DM is opened from the DM list', () => {
        visit('/w/snack-hq/dms');

        expect(visit('/w/snack-hq/dms/01HZXCONVERSATION')).toBe(
            'direct-messages',
        );
    });

    it('holds the section across a walk from one conversation to another', () => {
        visit('/w/snack-hq/dms');
        visit('/w/snack-hq/dms/01HZXONE');

        expect(visit('/w/snack-hq/dms/01HZXTWO')).toBe('direct-messages');
    });

    it('leaves direct messages again on the way back to a channel', () => {
        visit('/w/snack-hq/dms');
        visit('/w/snack-hq/dms/01HZXCONVERSATION');

        expect(visit('/w/snack-hq/channels/general')).toBe('workspace');
    });

    it('lets the rail claim the section it is redirected past', () => {
        // `GET /dms` redirects into the newest conversation, so the rail's own
        // destination lands on the URL that inherits — the section would be
        // unreachable if the click could not say where it was going.
        visit('/w/snack-hq/channels/general');
        enterSection('direct-messages');

        expect(visit('/w/snack-hq/dms/01HZXCONVERSATION')).toBe(
            'direct-messages',
        );
    });

    it('opens a shared conversation link beside the channel list', () => {
        // Nothing to inherit on a cold load, and the workspace shape is the one
        // that can reach everywhere else.
        expect(visit('/w/snack-hq/dms/01HZXCONVERSATION')).toBe('workspace');
    });

    it('survives a reload of the conversation it was opened in', () => {
        visit('/w/snack-hq/dms');
        visit('/w/snack-hq/dms/01HZXCONVERSATION');

        // A reload is a fresh module and fresh components, with only the
        // per-tab storage left standing.
        expect(sessionStorage.getItem('snack:sidebar-section')).toBe(
            'direct-messages',
        );
    });
});
