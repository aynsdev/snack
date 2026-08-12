import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Welcome from '@/pages/welcome';

const usePage = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage,
    Head: () => null,
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

beforeEach(() => {
    usePage.mockReturnValue({ props: { auth: { user: null } } });
});

/**
 * The landing page is the only page in the application whose content *is* the
 * feature, so what is worth asserting is not that it renders but that it stays
 * navigable as it grows: the jump links in the header are only useful while
 * every one of them resolves to a section that exists.
 */
describe('Welcome', () => {
    it('gives every jump link a section to land on', () => {
        const { container } = render(<Welcome />);

        const targets = [...container.querySelectorAll('a[href^="#"]')].map(
            (link) => link.getAttribute('href')?.slice(1),
        );

        expect(targets.length).toBeGreaterThan(0);

        for (const id of targets) {
            expect(container.querySelector(`section#${id}`)).not.toBeNull();
        }
    });

    it('gives every section a heading a screen reader can announce', () => {
        const { container } = render(<Welcome />);

        const sections = [...container.querySelectorAll('section[id]')];

        for (const section of sections) {
            const labelledBy = section.getAttribute('aria-labelledby');

            expect(labelledBy).not.toBeNull();
            expect(
                document.getElementById(labelledBy as string),
            ).not.toBeNull();
        }
    });

    /*
     * A landing page for a product this broad is worth nothing if it reads as a
     * chat app, so these four are the ones whose absence would be a regression
     * rather than an edit.
     */
    it.each(['Conversation', 'Projects', 'Knowledge', 'Foundations'])(
        'covers %s',
        (pillar) => {
            const { container } = render(<Welcome />);

            expect(
                within(container).getAllByText(pillar).length,
            ).toBeGreaterThan(0);
        },
    );

    /**
     * Snack is invitation-first: there is no public registration route, so a
     * "sign up" call to action would send people somewhere that deliberately
     * 404s.
     */
    it('asks a signed-out visitor to sign in, never to sign up', () => {
        render(<Welcome />);

        expect(screen.getAllByText('Sign in').length).toBeGreaterThan(0);
        expect(screen.queryByText(/sign up/i)).toBeNull();
        expect(screen.queryByText(/get started/i)).toBeNull();
    });

    it('sends a signed-in visitor to their workspace instead', () => {
        usePage.mockReturnValue({
            props: { auth: { user: { id: 1, name: 'Ada' } } },
        });

        render(<Welcome />);

        expect(screen.queryByText('Sign in')).toBeNull();
        // Hero and closing CTA both swap, so neither is left telling a
        // signed-in reader to sign in again.
        expect(screen.getAllByText('Open your workspace')).toHaveLength(2);
    });
});
