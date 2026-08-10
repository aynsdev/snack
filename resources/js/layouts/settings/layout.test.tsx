import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SettingsLayout from '@/layouts/settings/layout';

const useCurrentWorkspace = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
        ...props
    }: {
        children: React.ReactNode;
        href: string | { url: string };
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...props}>
            {children}
        </a>
    ),
}));

vi.mock('@/hooks/use-current-url', () => ({
    useCurrentUrl: () => ({ isCurrentOrParentUrl: () => false }),
}));

vi.mock('@/hooks/use-workspace', () => ({ useCurrentWorkspace }));

function currentWorkspace(administer: boolean) {
    return {
        workspace: {
            slug: 'snack-hq',
            abilities: { administer },
        },
    };
}

beforeEach(() => useCurrentWorkspace.mockReset());

describe('SettingsLayout', () => {
    it('offers workspace creation to a workspace admin', () => {
        useCurrentWorkspace.mockReturnValue(currentWorkspace(true));

        render(
            <SettingsLayout>
                <div>Settings content</div>
            </SettingsLayout>,
        );

        expect(
            screen.getByRole('link', { name: 'Create workspace' }),
        ).toHaveAttribute('href', '/workspaces/create');
        expect(
            screen.getByRole('link', { name: 'Notifications' }),
        ).toHaveAttribute('href', '/w/snack-hq/notification-preferences');
    });

    it('does not offer workspace creation to a regular member', () => {
        useCurrentWorkspace.mockReturnValue(currentWorkspace(false));

        render(
            <SettingsLayout>
                <div>Settings content</div>
            </SettingsLayout>,
        );

        expect(
            screen.queryByRole('link', { name: 'Create workspace' }),
        ).not.toBeInTheDocument();
    });
});
