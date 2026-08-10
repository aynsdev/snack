import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WorkspaceRail } from '@/components/snack/workspace-rail';
import { TooltipProvider } from '@/components/ui/tooltip';

const usePage = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage,
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

const snackHq = {
    id: 1,
    name: 'Snack HQ',
    slug: 'snack-hq',
    initials: 'SH',
    logo_url: null,
};

function renderRail(workspaces: (typeof snackHq)[]) {
    usePage.mockReturnValue({
        props: {
            snack: { workspaces, current: null },
        },
    });

    return render(
        <TooltipProvider>
            <WorkspaceRail currentWorkspaceSlug="snack-hq" />
        </TooltipProvider>,
    );
}

beforeEach(() => usePage.mockReset());

describe('WorkspaceRail', () => {
    it('hides the workspace column when there is only one workspace', () => {
        renderRail([snackHq]);

        expect(
            screen.queryByRole('navigation', { name: 'Workspaces' }),
        ).not.toBeInTheDocument();
    });

    it('shows the workspace switcher when there are multiple workspaces', () => {
        renderRail([
            snackHq,
            {
                id: 2,
                name: 'Design Club',
                slug: 'design-club',
                initials: 'DC',
                logo_url: null,
            },
        ]);

        expect(
            screen.getByRole('navigation', { name: 'Workspaces' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Snack HQ' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            screen.getByRole('link', { name: 'Design Club' }),
        ).toHaveAttribute('href', '/w/design-club');
    });
});
