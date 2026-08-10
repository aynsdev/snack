import { describe, expect, it } from 'vitest';
import { usesSettingsLayout } from '@/lib/page-layout';

describe('usesSettingsLayout', () => {
    it.each(['settings/profile', 'settings/security', 'settings/appearance'])(
        'uses settings navigation for %s',
        (pageName) => {
            expect(usesSettingsLayout(pageName)).toBe(true);
        },
    );

    it('uses settings navigation for workspace notification preferences', () => {
        expect(usesSettingsLayout('notifications/preferences')).toBe(true);
    });

    it('leaves regular workspace pages in the workspace layout', () => {
        expect(usesSettingsLayout('channels/show')).toBe(false);
    });
});
